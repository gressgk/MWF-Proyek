<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// ============================================================
// Helper: Buat signature Midtrans yang valid
// SHA512(order_id + status_code + gross_amount + signature_secret_key)
// ============================================================

function makeWebhookSignature(string $orderId, string $statusCode, string $grossAmount, ?string $signingSecret = null): string
{
    $secret = $signingSecret ?? config('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
    return hash('sha512', $orderId . $statusCode . $grossAmount . $secret);
}

function webhookPayload(array $overrides = []): array
{
    $signingSecret = config('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
    $orderId = $overrides['order_id'] ?? 'DON-TEST-WEBHOOK-001';
    $statusCode = $overrides['status_code'] ?? '200';
    $grossAmount = $overrides['gross_amount'] ?? '50000.00';

    return array_merge([
        'order_id'            => $orderId,
        'status_code'         => $statusCode,
        'gross_amount'        => $grossAmount,
        'transaction_status'  => 'settlement',
        'fraud_status'        => 'accept',
        'signature_key'       => makeWebhookSignature($orderId, $statusCode, $grossAmount, $signingSecret),
    ], $overrides);
}

function createPendingDonation(array $attrs = []): Donation
{
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create(array_merge([
        'user_id'           => $penerima->id,
        'judul'             => 'Test Campaign Webhook',
        'slug'              => 'test-campaign-webhook-' . rand(1000, 9999),
        'deskripsi'         => 'Deskripsi test',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ], $attrs['campaign'] ?? []));

    $donatur = $attrs['donatur'] ?? User::factory()->donatur()->create();

    return Donation::create(array_merge([
        'campaign_id'          => $campaign->id,
        'user_id'              => $donatur->id,
        'jenis'                => 'uang',
        'nominal'              => 50000,
        'order_id'             => 'DON-TEST-WEBHOOK-001',
        'status'               => 'menunggu_pembayaran',
        'idempotency_key'      => Str::uuid()->toString(),
        'is_anonim'            => false,
        'payment_status_detail'=> 'snap_created',
        'snap_token'           => 'snap-mock-token',
    ], $attrs['donation'] ?? []));
}

// ============================================================
// Setup: safety guard + config default
// ============================================================

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }

    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY_12345');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY_12345');
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
    Config::set('services.midtrans.is_production', false);

    Http::preventStrayRequests();
});

// ============================================================
// 1. SETTLEMENT VALID → TERVERIFIKASI
// ============================================================

test('valid settlement notification verifies donation and updates campaign progress', function () {
    $donation = createPendingDonation();
    $campaign = Campaign::find($donation->campaign_id);

    expect((int) $campaign->terkumpul_nominal)->toBe(0);

    $payload = webhookPayload([
        'order_id'           => $donation->order_id,
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'status_code'        => '200',
    ]);

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('settled');
    expect($donation->webhook_transaction_status)->toBe('settlement');
    expect($donation->webhook_processed_at)->not->toBeNull();

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 2. CAPTURE + FRAUD ACCEPT → TERVERIFIKASI
// ============================================================

test('valid capture with fraud_status accept verifies donation', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-CAPTURE-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-CAPTURE-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'capture',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-CAPTURE-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
});

// ============================================================
// 3. CAPTURE + FRAUD CHALLENGE & DENY
// ============================================================

test('capture with fraud_status challenge retains status menunggu_pembayaran with payment_challenge detail', function () {
    // Sesuai aturan Midtrans: challenge bukan kegagalan final.
    // Transaksi masih menunggu review manual di MAP.
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-CAPTURE-CHALLENGE-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-CAPTURE-CHALLENGE-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'capture',
        'fraud_status'       => 'challenge',
        'signature_key'      => makeWebhookSignature('DON-CAPTURE-CHALLENGE-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    // BUKAN gagal — masih menunggu_pembayaran
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect($donation->payment_status_detail)->toBe('payment_challenge');
    expect($donation->webhook_fraud_status)->toBe('challenge');
});

test('capture with fraud_status deny marks donation as gagal', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-CAPTURE-DENY-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-CAPTURE-DENY-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'capture',
        'fraud_status'       => 'deny',
        'signature_key'      => makeWebhookSignature('DON-CAPTURE-DENY-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('gagal');
    expect($donation->payment_status_detail)->toBe('payment_deny');
});

// ============================================================
// 4. SIGNATURE VERIFICATION (SIGNATURE SECRET KEY VS SERVER KEY)
// ============================================================

test('webhook signed with signature secret key is accepted', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-SIG-SECRET-001']]);
    $signingSecret = config('services.midtrans.signature_secret_key');

    $payload = [
        'order_id'           => 'DON-SIG-SECRET-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-SIG-SECRET-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
});

test('webhook signed with server key API is rejected with 403 when secrets differ', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-SIG-SERVERKEY-001']]);

    $serverKey = config('services.midtrans.server_key');
    $signingSecret = config('services.midtrans.signature_secret_key');

    // Pastikan kedua secret berbeda pada environment test
    expect($serverKey)->not->toBe($signingSecret);

    // Payload ditandatangani menggunakan SERVER KEY API (bukan Signature Secret Key)
    $payload = [
        'order_id'           => 'DON-SIG-SERVERKEY-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-SIG-SERVERKEY-001', '200', '50000.00', $serverKey),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    // Status donation tetap menunggu_pembayaran
    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

test('invalid signature is rejected with 403', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-SIG-TEST-001']]);

    $payload = [
        'order_id'           => 'DON-SIG-TEST-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => 'invalid-signature-aaabbbccc', // salah
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    // Status donation tidak berubah
    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

test('webhook with empty signature_key is rejected with 400', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-SIG-EMPTY-001']]);

    $payload = [
        'order_id'           => 'DON-SIG-EMPTY-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => '',
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(400)->assertJson(['message' => 'Invalid payload']);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

test('missing signature secret key configuration returns 503 service unavailable safely', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-SIG-MISSING-CONF-001']]);

    Config::set('services.midtrans.signature_secret_key', null);

    $payload = [
        'order_id'           => 'DON-SIG-MISSING-CONF-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => 'some-dummy-signature',
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(503)->assertJson(['message' => 'Service unavailable']);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 5. NOMINAL TIDAK COCOK → 422
// ============================================================

test('webhook with mismatched gross_amount is rejected with 422', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-MISMATCH-001',
        'nominal'  => 50000,
    ]]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    // Provider mengirim 75000 padahal database menyimpan 50000
    $payload = [
        'order_id'           => 'DON-MISMATCH-001',
        'status_code'        => '200',
        'gross_amount'       => '75000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-MISMATCH-001', '200', '75000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(422);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
    // Progress tidak berubah
    $campaign = Campaign::find($donation->campaign_id);
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 6. ORDER ID TIDAK DITEMUKAN → 200 (tidak error, tidak retry)
// ============================================================

test('webhook for unknown order_id returns 200 without side effects', function () {
    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-NONEXISTENT-999',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-NONEXISTENT-999', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    // 200 agar Midtrans tidak retry secara terus-menerus
    $response->assertStatus(200);
    expect(Donation::count())->toBe(0);
});

// ============================================================
// 7. PENDING NOTIFICATION → TIDAK MENAMBAH PROGRESS
// ============================================================

test('pending notification does not change donation status or campaign progress', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-PENDING-001']]);
    $campaign = Campaign::find($donation->campaign_id);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-PENDING-001',
        'status_code'        => '201',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'pending',
        'fraud_status'       => null,
        'signature_key'      => makeWebhookSignature('DON-PENDING-001', '201', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 8. EXPIRE → GAGAL, TIDAK MENAMBAH PROGRESS
// ============================================================

test('expire notification marks donation as gagal and does not update campaign progress', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-EXPIRE-001']]);
    $campaign = Campaign::find($donation->campaign_id);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-EXPIRE-001',
        'status_code'        => '407',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'expire',
        'fraud_status'       => null,
        'signature_key'      => makeWebhookSignature('DON-EXPIRE-001', '407', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('gagal');
    expect($donation->payment_status_detail)->toBe('payment_expire');

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 9. DENY → GAGAL
// ============================================================

test('deny notification marks donation as gagal', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-DENY-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-DENY-001',
        'status_code'        => '202',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'deny',
        'fraud_status'       => 'deny',
        'signature_key'      => makeWebhookSignature('DON-DENY-001', '202', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('gagal');
});

// ============================================================
// 10. DUPLIKAT WEBHOOK → TIDAK DOUBLE COUNTING
// ============================================================

test('duplicate settlement webhook does not double count campaign progress', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-DUPLICATE-001']]);
    $campaign = Campaign::find($donation->campaign_id);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-DUPLICATE-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-DUPLICATE-001', '200', '50000.00', $signingSecret),
    ];

    // Webhook pertama
    $this->postJson(route('midtrans.webhook'), $payload)->assertStatus(200);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);

    // Webhook kedua (duplikat)
    $this->postJson(route('midtrans.webhook'), $payload)->assertStatus(200);
    $campaign->refresh();
    // Harus tetap 50000, bukan 100000
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
    expect(Donation::where('order_id', 'DON-DUPLICATE-001')->count())->toBe(1);
});

// ============================================================
// 11. OUT-OF-ORDER NOTIFICATION — STATUS FINAL TIDAK BISA DIUBAH
// ============================================================

test('out-of-order expire notification after settlement does not revert terverifikasi status', function () {
    // Skenario: settlement sudah diterima, kemudian expire datang terlambat
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-ORDER-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // Settlement pertama
    $settlementPayload = [
        'order_id'           => 'DON-ORDER-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-ORDER-001', '200', '50000.00', $signingSecret),
    ];
    $this->postJson(route('midtrans.webhook'), $settlementPayload)->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');

    // Expire terlambat — harus diabaikan
    $expirePayload = [
        'order_id'           => 'DON-ORDER-001',
        'status_code'        => '407',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'expire',
        'fraud_status'       => null,
        'signature_key'      => makeWebhookSignature('DON-ORDER-001', '407', '50000.00', $signingSecret),
    ];
    $this->postJson(route('midtrans.webhook'), $expirePayload)->assertStatus(200);

    // Status TETAP terverifikasi
    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');

    // Progress TIDAK berubah
    $campaign = Campaign::find($donation->campaign_id);
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 12. DUA CAMPAIGN — PROGRESS TERPISAH
// ============================================================

test('two campaigns have independent progress from separate donations', function () {
    $penerima = User::factory()->penerima()->create();

    $campaign1 = Campaign::create([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Alpha',
        'slug'              => 'campaign-alpha-' . rand(1000, 9999),
        'deskripsi'         => 'Deskripsi Alpha',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 1000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ]);

    $campaign2 = Campaign::create([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Beta',
        'slug'              => 'campaign-beta-' . rand(1000, 9999),
        'deskripsi'         => 'Deskripsi Beta',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 2000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ]);

    $donatur = User::factory()->donatur()->create();

    Donation::create([
        'campaign_id'           => $campaign1->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'order_id'              => 'DON-ALPHA-001',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => Str::uuid()->toString(),
        'payment_status_detail' => 'snap_created',
    ]);

    Donation::create([
        'campaign_id'           => $campaign2->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 200000,
        'order_id'              => 'DON-BETA-001',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => Str::uuid()->toString(),
        'payment_status_detail' => 'snap_created',
    ]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // Settlement campaign 1
    $this->postJson(route('midtrans.webhook'), [
        'order_id'           => 'DON-ALPHA-001',
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-ALPHA-001', '200', '100000.00', $signingSecret),
    ])->assertStatus(200);

    // Settlement campaign 2
    $this->postJson(route('midtrans.webhook'), [
        'order_id'           => 'DON-BETA-001',
        'status_code'        => '200',
        'gross_amount'       => '200000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-BETA-001', '200', '200000.00', $signingSecret),
    ])->assertStatus(200);

    $campaign1->refresh();
    $campaign2->refresh();

    expect((int) $campaign1->terkumpul_nominal)->toBe(100000);
    expect((int) $campaign2->terkumpul_nominal)->toBe(200000);
});

// ============================================================
// 13. DONASI ANONIM — USER_ID TETAP TERHUBUNG INTERNAL
// ============================================================

test('anonymous donation webhook verifies and preserves internal user_id link', function () {
    $donatur = User::factory()->donatur()->create();
    $donation = createPendingDonation([
        'donatur'  => $donatur,
        'donation' => [
            'order_id'  => 'DON-ANONIM-001',
            'is_anonim' => true,
        ],
    ]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-ANONIM-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-ANONIM-001', '200', '50000.00', $signingSecret),
    ];

    $this->postJson(route('midtrans.webhook'), $payload)->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
    // user_id tetap terhubung secara internal
    expect($donation->user_id)->toBe($donatur->id);
    // is_anonim tidak berubah
    expect((bool) $donation->is_anonim)->toBeTrue();
});

// ============================================================
// 14. DONASI BARANG TIDAK DAPAT DIVERIFIKASI LEWAT WEBHOOK UANG
// ============================================================

test('webhook cannot verify a barang donation via uang webhook endpoint', function () {
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Barang Test',
        'slug'              => 'campaign-barang-test-' . rand(1000, 9999),
        'deskripsi'         => 'Deskripsi',
        'tipe_bantuan'      => 'barang',
        'target_barang'     => 10,
        'satuan_barang'     => 'karton',
        'terkumpul_barang'  => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ]);

    $donatur = User::factory()->donatur()->create();

    Donation::create([
        'campaign_id'      => $campaign->id,
        'user_id'          => $donatur->id,
        'jenis'            => 'barang', // <-- bukan 'uang'
        'nominal'          => 0,
        'order_id'         => 'DON-BARANG-001',
        'status'           => 'menunggu_pembayaran',
        'idempotency_key'  => Str::uuid()->toString(),
    ]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-BARANG-001',
        'status_code'        => '200',
        'gross_amount'       => '0.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-BARANG-001', '200', '0.00', $signingSecret),
    ];

    // Controller: WHERE jenis='uang' — donasi barang tidak akan ditemukan
    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200); // 200 bukan error, tapi order "tidak ditemukan" untuk jenis uang

    $barangDonation = Donation::where('order_id', 'DON-BARANG-001')->first();
    expect($barangDonation->status)->toBe('menunggu_pembayaran'); // tidak berubah
    expect((int) $campaign->fresh()->terkumpul_barang)->toBe(0);
});

// ============================================================
// 15. REFUND / CHARGEBACK — KONSERVATIF, STATUS FINAL DIPERTAHANKAN
// ============================================================

test('refund notification on a terverifikasi donation is logged conservatively without reverting status', function () {
    // Skenario: Donation sudah terverifikasi, kemudian Midtrans kirim refund
    $donation = createPendingDonation(['donation' => [
        'order_id'              => 'DON-REFUND-001',
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'settled',
    ]]);

    // Simulasi: campaign sudah mencatat progress
    Campaign::where('id', $donation->campaign_id)->update(['terkumpul_nominal' => 50000]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    $payload = [
        'order_id'           => 'DON-REFUND-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'refund',
        'fraud_status'       => null,
        'signature_key'      => makeWebhookSignature('DON-REFUND-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    // Status tetap terverifikasi (konservatif — tidak otomatis dibalik tanpa aturan jelas)
    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');

    // webhook_transaction_status diperbarui untuk audit
    expect($donation->webhook_transaction_status)->toBe('refund');

    // Progress campaign tidak dihitung ulang (refund tidak menjadi status 'terverifikasi' baru)
    $campaign = Campaign::find($donation->campaign_id);
    // terkumpul_nominal direcalculate berdasarkan SUM — karena masih status terverifikasi, nilainya 50000
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 16. BROWSER CALLBACK PALSU — TIDAK DAPAT MEMVERIFIKASI PEMBAYARAN
// ============================================================

test('fake browser callback cannot verify payment by hitting any existing endpoint', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-FAKECALLBACK-001']]);

    // Donatur mencoba memalsukan verifikasi lewat query parameter di URL riwayat
    $donatur = User::factory()->donatur()->create();
    $response = $this->actingAs($donatur)->get(route('donatur.riwayat', [
        'order_id'           => $donation->order_id,
        'transaction_status' => 'settlement',
        'status_code'        => '200',
    ]));

    $response->assertStatus(200);

    // Status donation tidak berubah
    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');

    // Campaign progress tidak berubah
    $campaign = Campaign::find($donation->campaign_id);
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 17. PAYLOAD TIDAK LENGKAP → 400
// ============================================================

test('webhook with missing required fields returns 400', function () {
    $response = $this->postJson(route('midtrans.webhook'), [
        'order_id' => 'DON-INCOMPLETE-001',
        // missing status_code, gross_amount, transaction_status, signature_key
    ]);

    $response->assertStatus(400);
    expect(Donation::count())->toBe(0);
});

// ============================================================
// 18. RECONCILIATION: SETTLEMENT VIA CHECK STATUS API
// ============================================================

test('reconcileDonation marks settled donation as terverifikasi and recalculates campaign progress', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-001/status' => Http::response([
            'order_id'           => 'DON-RECON-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-RECON-001']]);
    $campaign = Campaign::find($donation->campaign_id);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeTrue();
    expect($result['status'])->toBe('terverifikasi');

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('settled');

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 19. RECONCILIATION: EXPIRED VIA CHECK STATUS API
// ============================================================

test('reconcileDonation marks expired donation as gagal', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-EXP-001/status' => Http::response([
            'order_id' => 'DON-RECON-EXP-001',
            'status_code' => '407',
            'transaction_status' => 'expire',
            'gross_amount' => '50000.00',
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-RECON-EXP-001']]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeTrue();
    expect($result['status'])->toBe('gagal');

    $donation->refresh();
    expect($donation->status)->toBe('gagal');
    expect($donation->payment_status_detail)->toBe('payment_expire');
});

// ============================================================
// 20. RECONCILIATION: ORDER NOT FOUND ON PROVIDER
// ============================================================

test('reconcileDonation handles not_found on provider without changing donation status', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-NF-001/status' => Http::response([], 404),
    ]);

    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-RECON-NF-001']]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeFalse();
    expect($result['detail'])->toBe('order_not_found_on_provider');

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 21. RECONCILIATION: DOES NOT ALTER ALREADY FINAL STATUS
// ============================================================

test('reconcileDonation does not alter already final donation status', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECON-FINAL-001',
        'status'   => 'terverifikasi',
    ]]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeFalse();
    expect($result['detail'])->toBe('already_final');

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
});

test('reconciliation get status api uses server key for basic authentication', function () {
    $expectedServerKey = 'TEST_ONLY_SERVER_KEY_12345';
    Config::set('services.midtrans.server_key', $expectedServerKey);
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-AUTH-CHECK/status' => Http::response([
            'order_id'           => 'DON-AUTH-CHECK',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-AUTH-CHECK',
        'nominal'  => 50000,
    ]]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeTrue();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($expectedServerKey) {
        $authHeader = $request->header('Authorization');
        return $authHeader === ['Basic ' . base64_encode($expectedServerKey . ':')];
    });
});

// ============================================================
// 22. E3.1: STATUS CODE & TRANSACTION STATUS CONSISTENCY
// ============================================================

test('valid signature with inconsistent status_code and transaction_status is rejected with 422', function () {
    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-INCONSISTENT-001']]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    // Inconsistent: transaction_status is settlement but status_code is 407 (expire)
    $payload = [
        'order_id'           => 'DON-INCONSISTENT-001',
        'status_code'        => '407',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-INCONSISTENT-001', '407', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(422);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 23. E3.1: FRACTIONAL GROSS AMOUNT REJECTED
// ============================================================

test('webhook rejects fractional gross amount that does not match integer IDR exactly', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-FRACTION-001',
        'nominal'  => 50000,
    ]]);

    $signingSecret = config('services.midtrans.signature_secret_key');
    // Gross amount includes cents (.50) which is invalid for integer IDR
    $payload = [
        'order_id'           => 'DON-FRACTION-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.50',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-FRACTION-001', '200', '50000.50', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(422);

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 24. E3.1: RECONCILIATION WITH MISMATCHED GROSS AMOUNT
// ============================================================

test('reconciliation rejects mismatched gross amount from provider without updating status', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-MISMATCH-001/status' => Http::response([
            'order_id'           => 'DON-RECON-MISMATCH-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '99999.00', // Berbeda dari 50000
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECON-MISMATCH-001',
        'nominal'  => 50000,
    ]]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeFalse();
    expect($result['detail'])->toBe('amount_mismatch');

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 25. E3.1: OUT-OF-ORDER RECOVERY VIA PROVIDER CONFIRMATION
// ============================================================

test('settlement webhook after prior expire failure recovers status when confirmed by provider', function () {
    // Skenario: transaksi sebelumnya sudah terlanjur berstatus 'gagal' karena expire
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECOVER-001',
        'status'   => 'gagal',
        'payment_status_detail' => 'payment_expire',
    ]]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // Mock API status provider yang mengonfirmasi bahwa transaksi sebenarnya telah 'settlement'
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECOVER-001/status' => Http::response([
            'order_id'           => 'DON-RECOVER-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $payload = [
        'order_id'           => 'DON-RECOVER-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-RECOVER-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    // Status berhasil dipulihkan menjadi terverifikasi
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('settled');

    $campaign = Campaign::find($donation->campaign_id);
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 26. E3.1: TWO DONATIONS ON SAME CAMPAIGN CONCURRENT LOCKING
// ============================================================

test('two donations on the same campaign update progress accurately without lost update', function () {
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Shared Lock Test',
        'slug'              => 'campaign-shared-lock-' . rand(1000, 9999),
        'deskripsi'         => 'Deskripsi shared lock',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ]);

    $donatur = User::factory()->donatur()->create();

    $donation1 = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'order_id'              => 'DON-SHARED-001',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => Str::uuid()->toString(),
        'payment_status_detail' => 'snap_created',
    ]);

    $donation2 = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 150000,
        'order_id'              => 'DON-SHARED-002',
        'status'                => 'menunggu_pembayaran',
        'idempotency_key'       => Str::uuid()->toString(),
        'payment_status_detail' => 'snap_created',
    ]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // Webhook 1 diproses
    $this->postJson(route('midtrans.webhook'), [
        'order_id'           => 'DON-SHARED-001',
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-SHARED-001', '200', '100000.00', $signingSecret),
    ])->assertStatus(200);

    // Webhook 2 diproses
    $this->postJson(route('midtrans.webhook'), [
        'order_id'           => 'DON-SHARED-002',
        'status_code'        => '200',
        'gross_amount'       => '150000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-SHARED-002', '200', '150000.00', $signingSecret),
    ])->assertStatus(200);

    $campaign->refresh();
    // Keduanya harus terakumulasi secara akurat menjadi 250.000
    expect((int) $campaign->terkumpul_nominal)->toBe(250000);
});

// ============================================================
// 27. E3.1: CONSISTENCY BETWEEN WEBHOOK AND GET STATUS API
// ============================================================

test('webhook and get status api achieve identical state transitions and details', function () {
    $donationWebhook = createPendingDonation(['donation' => ['order_id' => 'DON-IDENTICAL-WEBHOOK']]);
    $donationRecon   = createPendingDonation(['donation' => ['order_id' => 'DON-IDENTICAL-RECON']]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // 1. Eksekusi via Webhook
    $this->postJson(route('midtrans.webhook'), [
        'order_id'           => 'DON-IDENTICAL-WEBHOOK',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-IDENTICAL-WEBHOOK', '200', '50000.00', $signingSecret),
    ])->assertStatus(200);

    // 2. Eksekusi via Reconcile API
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-IDENTICAL-RECON/status' => Http::response([
            'order_id'           => 'DON-IDENTICAL-RECON',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $service->reconcileDonation($donationRecon);

    $donationWebhook->refresh();
    $donationRecon->refresh();

    // Verifikasi status dan detail identik
    expect($donationWebhook->status)->toBe($donationRecon->status);
    expect($donationWebhook->status)->toBe('terverifikasi');
    expect($donationWebhook->payment_status_detail)->toBe($donationRecon->payment_status_detail);
    expect($donationWebhook->payment_status_detail)->toBe('settled');
});

// ============================================================
// 28. E3.2: RECONCILIATION STRICT VALIDATION ON GET STATUS API
// ============================================================

test('settlement Get Status API without gross_amount is rejected and does not alter status', function () {
    // Sesuai E3.2: tidak boleh menggunakan nominal database sebagai fallback jika provider tidak mengirim gross_amount
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-NO-GROSS-001/status' => Http::response([
            'order_id'           => 'DON-NO-GROSS-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            // gross_amount tidak ada
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-NO-GROSS-001']]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeFalse();
    expect($result['detail'])->toBe('incomplete_or_mismatched_provider_data');

    $donation->refresh();
    // Status tetap menunggu_pembayaran
    expect($donation->status)->toBe('menunggu_pembayaran');
});

test('Get Status API without order_id or status_code is rejected by reconciliation', function () {
    // Response provider tanpa order_id atau status_code
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-NO-ORDERID-001/status' => Http::response([
            'transaction_status' => 'settlement',
            'gross_amount'       => '50000.00',
            // order_id dan status_code hilang
        ], 200),
    ]);

    $donation = createPendingDonation(['donation' => ['order_id' => 'DON-NO-ORDERID-001']]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->reconcileDonation($donation);

    expect($result['reconciled'])->toBeFalse();
    expect($result['detail'])->toBe('incomplete_or_mismatched_provider_data');

    $donation->refresh();
    expect($donation->status)->toBe('menunggu_pembayaran');
});

// ============================================================
// 29. E3.2: OUT-OF-ORDER RECOVERY INCOMPLETE PROVIDER DATA
// ============================================================

test('out-of-order recovery is rejected if provider response is incomplete', function () {
    // Skenario: webhook mengklaim settlement setelah status gagal, tetapi saat diperiksa ke Get Status API, data tidak lengkap
    $donation = createPendingDonation(['donation' => [
        'order_id'              => 'DON-RECOVER-INCOMPLETE-001',
        'status'                => 'gagal',
        'payment_status_detail' => 'payment_expire',
    ]]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECOVER-INCOMPLETE-001/status' => Http::response([
            'order_id'           => 'DON-RECOVER-INCOMPLETE-001',
            'transaction_status' => 'settlement',
            // gross_amount dan status_code tidak disertakan oleh provider
        ], 200),
    ]);

    $payload = [
        'order_id'           => 'DON-RECOVER-INCOMPLETE-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-RECOVER-INCOMPLETE-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    // Status TETAP gagal (tidak dipulihkan karena data provider tidak lengkap)
    expect($donation->status)->toBe('gagal');
});

// ============================================================
// 30. E3.2: TIMEOUT DURING OUT-OF-ORDER RECOVERY DOES NOT BLOCK
// ============================================================

test('timeout during out-of-order recovery check leaves status gagal without corrupting state', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id'              => 'DON-RECOVER-TIMEOUT-001',
        'status'                => 'gagal',
        'payment_status_detail' => 'payment_expire',
    ]]);

    $signingSecret = config('services.midtrans.signature_secret_key');

    // Provider status API mengalami timeout
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECOVER-TIMEOUT-001/status' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        },
    ]);

    $payload = [
        'order_id'           => 'DON-RECOVER-TIMEOUT-001',
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature('DON-RECOVER-TIMEOUT-001', '200', '50000.00', $signingSecret),
    ];

    $response = $this->postJson(route('midtrans.webhook'), $payload);
    $response->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('gagal');
});

// ============================================================
// 31. E5.1.8C: STRICT AMOUNT, MIDDLEWARE, SIGNATURE & RECONCILIATION HARDENING
// ============================================================

test('e5.1.8c regular webhook: accepts valid signed settlement amount formats', function (mixed $grossAmount) {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-FMT-' . Str::random(6),
        'nominal'  => 50000,
    ]]);
    $campaign = Campaign::find($donation->campaign_id);
    $secret = config('services.midtrans.signature_secret_key');

    $payload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => $grossAmount,
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature($donation->order_id, '200', (string) $grossAmount, $secret),
    ];

    $this->postJson(route('midtrans.webhook'), $payload)
        ->assertStatus(200)
        ->assertJson(['message' => 'OK']);

    expect($donation->fresh()->status)->toBe('terverifikasi');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(50000);
})->with([
    'two decimals string' => ['50000.00'],
    'single zero decimal string' => ['50000.0'],
    'whole integer string' => ['50000'],
    'integer' => [50000],
]);

test('e5.1.8c regular webhook: rejects signature generated for different gross_amount representation with 403', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-SIGREP-001',
        'nominal'  => 50000,
    ]]);
    $secret = config('services.midtrans.signature_secret_key');

    // Signature dihitung dari '50000.00', tetapi payload mengirim '50000'
    $payload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '50000',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature($donation->order_id, '200', '50000.00', $secret),
    ];

    $this->postJson(route('midtrans.webhook'), $payload)
        ->assertStatus(403)
        ->assertJson(['message' => 'Invalid signature']);

    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
});

test('e5.1.8c regular webhook: rejects scientific notation even with valid synthetic signature', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-SCI-001',
        'nominal'  => 50000,
    ]]);
    $campaign = Campaign::find($donation->campaign_id);
    $secret = config('services.midtrans.signature_secret_key');

    $payload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '5e4',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature($donation->order_id, '200', '5e4', $secret),
    ];

    $this->postJson(route('midtrans.webhook'), $payload)
        ->assertStatus(422)
        ->assertJson(['message' => 'Amount mismatch']);

    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('e5.1.8c regular webhook: preserves raw whitespace and rejects raw JSON float or scientific numbers', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-RAW-001',
        'nominal'  => 50000,
    ]]);
    $campaign = Campaign::find($donation->campaign_id);
    $secret = config('services.midtrans.signature_secret_key');

    // 1. Whitespace tidak di-trim oleh TrimStrings di route webhook -> tetap ditolak 422
    $wsAmount = ' 50000.00 ';
    $wsSig = makeWebhookSignature($donation->order_id, '200', $wsAmount, $secret);
    $rawWsJson = json_encode([
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => $wsAmount,
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => $wsSig,
    ]);
    $this->call(
        'POST',
        route('midtrans.webhook'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawWsJson
    )->assertStatus(422)->assertJson(['message' => 'Amount mismatch']);

    // 2. Raw JSON integral float 50000.0 -> ditolak 400 (tidak jadi int 50000)
    $rawFloatJson = sprintf(
        '{"order_id":"%s","status_code":"200","gross_amount":50000.0,"transaction_status":"settlement","fraud_status":"accept","signature_key":"%s"}',
        $donation->order_id,
        makeWebhookSignature($donation->order_id, '200', '50000.0', $secret)
    );
    $this->call(
        'POST',
        route('midtrans.webhook'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawFloatJson
    )->assertStatus(400)->assertJson(['message' => 'Invalid payload']);

    // 3. Raw JSON scientific float 5e4 -> ditolak 400
    $rawSciJson = sprintf(
        '{"order_id":"%s","status_code":"200","gross_amount":5e4,"transaction_status":"settlement","fraud_status":"accept","signature_key":"%s"}',
        $donation->order_id,
        makeWebhookSignature($donation->order_id, '200', '5e4', $secret)
    );
    $this->call(
        'POST',
        route('midtrans.webhook'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawSciJson
    )->assertStatus(400)->assertJson(['message' => 'Invalid payload']);

    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('e5.1.8c regular webhook: rejects fractional DB nominal even when provider sends matching integer amount', function () {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-FRACDB-001',
        'nominal'  => 50000,
    ]]);
    DB::table('donations')->where('id', $donation->id)->update(['nominal' => '50000.99']);
    $donation->refresh();
    $campaign = Campaign::find($donation->campaign_id);
    $secret = config('services.midtrans.signature_secret_key');

    $payload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature($donation->order_id, '200', '50000.00', $secret),
    ];

    $this->postJson(route('midtrans.webhook'), $payload)
        ->assertStatus(422)
        ->assertJson(['message' => 'Amount mismatch']);

    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('e5.1.8c regular webhook: rejects malformed amount and signature types safely with 400', function (array $overrides) {
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-E518C-MALTYPE-' . Str::random(5),
        'nominal'  => 50000,
    ]]);
    $secret = config('services.midtrans.signature_secret_key');

    $payload = array_merge([
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '50000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => makeWebhookSignature($donation->order_id, '200', '50000.00', $secret),
    ], $overrides);

    $this->postJson(route('midtrans.webhook'), $payload)
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid payload']);

    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
})->with([
    'array gross_amount' => [['gross_amount' => ['50000.00']]],
    'bool gross_amount' => [['gross_amount' => true]],
    'empty gross_amount' => [['gross_amount' => '']],
    'array signature_key' => [['signature_key' => ['sig']]],
    'int signature_key' => [['signature_key' => 12345]],
    'bool signature_key' => [['signature_key' => true]],
    'whitespace signature_key' => [['signature_key' => '   ']],
]);

test('e5.1.8c regular reconciliation: rejects invalid provider amounts, fractional or null DB nominal, and null transition amount', function () {
    $service = app(\App\Services\MidtransPaymentService::class);

    // 1. Invalid provider amount formats on settlement -> rejected without mutation
    foreach (['5e4', ' 50000.00 ', '50000.', '0050000', ['50000.00']] as $badProviderAmount) {
        $donation = createPendingDonation(['donation' => [
            'order_id' => 'DON-RECON-BAD-' . Str::random(6),
            'nominal'  => 50000,
        ]]);
        Http::fake([
            'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => Http::response([
                'order_id'           => $donation->order_id,
                'status_code'        => '200',
                'transaction_status' => 'settlement',
                'fraud_status'       => 'accept',
                'gross_amount'       => $badProviderAmount,
            ], 200),
        ]);

        $res = $service->reconcileDonation($donation);
        expect($res['reconciled'])->toBeFalse();
        expect($res['detail'])->toBe('amount_mismatch');
        expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
    }

    // 2. Fractional DB nominal rejected during reconciliation
    $fracDonation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECON-FRAC-001',
        'nominal'  => 50000,
    ]]);
    DB::table('donations')->where('id', $fracDonation->id)->update(['nominal' => '50000.75']);
    $fracDonation->refresh();
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-FRAC-001/status' => Http::response([
            'order_id'           => 'DON-RECON-FRAC-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);
    $fracRes = $service->reconcileDonation($fracDonation);
    expect($fracRes['reconciled'])->toBeFalse();
    expect($fracRes['detail'])->toBe('amount_mismatch');
    expect($fracDonation->fresh()->status)->toBe('menunggu_pembayaran');

    // 3. Null DB nominal rejected during reconciliation
    $nullDonation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECON-NULL-001',
        'nominal'  => null,
    ]]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-NULL-001/status' => Http::response([
            'order_id'           => 'DON-RECON-NULL-001',
            'status_code'        => '200',
            'transaction_status' => 'settlement',
            'fraud_status'       => 'accept',
            'gross_amount'       => '50000.00',
        ], 200),
    ]);
    $nullRes = $service->reconcileDonation($nullDonation);
    expect($nullRes['reconciled'])->toBeFalse();
    expect($nullRes['detail'])->toBe('amount_mismatch');
    expect($nullDonation->fresh()->status)->toBe('menunggu_pembayaran');

    // 4. Invalid failure and pending provider amounts rejected
    $failDonation = createPendingDonation(['donation' => [
        'order_id' => 'DON-RECON-FAILBAD-001',
        'nominal'  => 50000,
    ]]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/DON-RECON-FAILBAD-001/status' => Http::response([
            'order_id'           => 'DON-RECON-FAILBAD-001',
            'status_code'        => '407',
            'transaction_status' => 'expire',
            'gross_amount'       => '5e4',
        ], 200),
    ]);
    $failRes = $service->reconcileDonation($failDonation);
    expect($failRes['reconciled'])->toBeFalse();
    expect($failRes['detail'])->toBe('incomplete_or_mismatched_provider_data');
    expect($failDonation->fresh()->status)->toBe('menunggu_pembayaran');

    // 5. Direct processTransactionTransition() cannot bypass verification by passing null grossAmount
    $directDonation = createPendingDonation(['donation' => [
        'order_id' => 'DON-DIRECT-NULL-001',
        'nominal'  => 50000,
    ]]);
    $directRes = $service->processTransactionTransition($directDonation, 'settlement', 'accept', null);
    expect($directRes['status'])->toBe('amount_mismatch');
    expect($directDonation->fresh()->status)->toBe('menunggu_pembayaran');
});

test('D0: reconcileDonation failure rejects non-IDR and malformed currency without marking donation gagal', function (mixed $badCurrency) {
    $service = app(\App\Services\MidtransPaymentService::class);
    $donation = createPendingDonation(['donation' => [
        'order_id' => 'DON-D0-RECON-CURR-' . Str::random(6),
        'nominal' => 50000,
    ]]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => Http::response([
            'order_id' => $donation->order_id,
            'status_code' => '407',
            'transaction_status' => 'expire',
            'gross_amount' => '50000.00',
            'currency' => $badCurrency,
        ], 200),
    ]);

    $res = $service->reconcileDonation($donation);
    expect($res['reconciled'])->toBeFalse();
    expect($res['detail'])->toBe('incomplete_or_mismatched_provider_data');
    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
})->with([
    'USD' => ['USD'],
    'empty string' => [''],
    'false bool' => [false],
    'zero int' => [0],
    'array' => [['IDR']],
]);
