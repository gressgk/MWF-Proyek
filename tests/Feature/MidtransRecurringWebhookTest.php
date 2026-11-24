<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// ============================================================
// Setup: safety guard + config default
// ============================================================

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }

    Config::set('services.midtrans.server_key', 'SB-Mid-server-test-key-12345');
    Config::set('services.midtrans.client_key', 'SB-Mid-client-test-key-12345');
    Config::set('services.midtrans.signature_secret_key', 'SB-Mid-signing-secret-test-key-67890');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.status_api_url', 'https://api.sandbox.midtrans.com/v2');
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');

    Http::preventStrayRequests();
});

// ============================================================
// Helper: Buat signature untuk recurring webhook
// SHA512(order_id + status_code + gross_amount + signature_secret_key)
// ============================================================

function makeRecurringSignature(string $orderId, string $statusCode, string $grossAmount, ?string $signingSecret = null): string
{
    $secret = $signingSecret ?? config('services.midtrans.signature_secret_key', 'SB-Mid-signing-secret-test-key-67890');
    return hash('sha512', $orderId . $statusCode . $grossAmount . $secret);
}

/**
 * Payload resmi Midtrans Subscription API (event_name, subscription, transaction).
 */
function officialRecurringPayload(array $overrides = []): array
{
    $subId       = $overrides['subscription_id'] ?? 'sub-midtrans-' . rand(10000, 99999);
    $orderId     = $overrides['order_id'] ?? 'SUB-ORD-' . rand(10000, 99999);
    $statusCode  = $overrides['status_code'] ?? '200';
    $grossAmount = $overrides['gross_amount'] ?? '100000.00';
    $paymentType = $overrides['payment_type'] ?? 'credit_card';
    $txStatus    = $overrides['transaction_status'] ?? 'settlement';
    $fraudStatus = array_key_exists('fraud_status', $overrides) ? $overrides['fraud_status'] : 'accept';

    $sigKey = array_key_exists('signature_key', $overrides)
        ? $overrides['signature_key']
        : makeRecurringSignature($orderId, $statusCode, $grossAmount);

    $txFields = [
        'transaction_id'     => 'tx-uuid-' . rand(1000, 9999),
        'order_id'           => $orderId,
        'transaction_status' => $txStatus,
        'status_code'        => $statusCode,
        'status_message'     => 'Success, transaction found',
        'gross_amount'       => $grossAmount,
        'fraud_status'       => $fraudStatus,
    ];
    if ($sigKey !== null) {
        $txFields['signature_key'] = $sigKey;
    }

    $subName = $overrides['subscription']['name'] ?? ('SUB-TEST-' . rand(100, 999));
    $txIds = [];
    if (str_starts_with($orderId, 'SUB-') || str_starts_with($orderId, 'sub-')) {
        $txIds = [$orderId, $txFields['transaction_id']];
    }

    // Default mock untuk verifikasi Get Subscription linkage provider
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => Http::response([
            'id'              => $subId,
            'name'            => $subName,
            'status'          => 'active',
            'transaction_ids' => $txIds,
        ], 200),
    ]);

    return [
        'event_name' => $overrides['event_name'] ?? 'subscription.charge',
        'subscription' => array_merge([
            'id'           => $subId,
            'name'         => 'SUB-TEST-' . rand(100, 999),
            'amount'       => $grossAmount,
            'currency'     => 'IDR',
            'payment_type' => $paymentType,
            'status'       => 'active',
            'schedule'     => [
                'interval'          => 1,
                'interval_unit'     => 'month',
                'current_interval'  => 1,
                'next_execution_at' => now()->addMonth()->toIso8601String(),
            ],
        ], $overrides['subscription'] ?? []),
        'transaction' => array_merge($txFields, $overrides['transaction'] ?? []),
    ];
}

/**
 * Buat Subscription yang aktif beserta Campaign dan User yang dibutuhkan.
 */
function createActiveSubscription(array $subAttrs = [], array $campaignAttrs = []): array
{
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create(array_merge([
        'user_id'           => $penerima->id,
        'judul'             => 'Campaign Recurring Test',
        'slug'              => 'campaign-recurring-test-' . rand(1000, 9999),
        'deskripsi'         => 'Test description',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ], $campaignAttrs));

    $donatur = User::factory()->donatur()->create();

    $subscription = Subscription::create(array_merge([
        'subscription_id' => 'sub-midtrans-' . rand(10000, 99999),
        'user_id'         => $donatur->id,
        'campaign_id'     => $campaign->id,
        'nominal'         => 100000,
        'frequency'       => 'bulanan',
        'interval'        => 1,
        'interval_unit'   => 'month',
        'payment_type'    => 'credit_card',
        'token'           => 'mock-cc-token',
        'status'          => 'active',
        'is_anonim'       => false,
        'idempotency_key' => Str::uuid()->toString(),
    ], $subAttrs, [
        'user_id'     => $donatur->id,
        'campaign_id' => $campaign->id,
    ]));

    return compact('campaign', 'donatur', 'subscription');
}

test('official nested subscription.create payload is acknowledged without mutations', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();

    $payload = [
        'event_name' => 'subscription.create',
        'subscription' => [
            'id'           => $sub->subscription_id,
            'status'       => 'active',
            'amount'       => '50000',
            'currency'     => 'IDR',
            'payment_type' => 'credit_card',
            'schedule'     => [
                'interval'      => 1,
                'interval_unit' => 'month',
            ],
            'token' => 'must-not-be-saved-or-logged',
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Subscription::count())->toBe(1);
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    expect($sub->fresh()->token)->not->toBe('must-not-be-saved-or-logged');
});

test('nested subscription.create unknown subscription is acknowledged without creating local data', function () {
    $payload = [
        'event_name' => 'subscription.create',
        'subscription' => [
            'id'           => 'sub-provider-unknown-' . time(),
            'status'       => 'active',
            'amount'       => '50000',
            'currency'     => 'IDR',
            'payment_type' => 'credit_card',
            'schedule'     => ['interval' => 1, 'interval_unit' => 'month'],
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Subscription::count())->toBe(0);
    expect(Donation::count())->toBe(0);
});

test('nested subscription.create duplicate notification is acknowledged and idempotent', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $payload = [
        'event_name' => 'subscription.create',
        'subscription' => [
            'id'           => $sub->subscription_id,
            'status'       => 'active',
            'amount'       => '100000',
            'currency'     => 'IDR',
            'payment_type' => 'credit_card',
            'schedule'     => ['interval' => 1, 'interval_unit' => 'month'],
        ],
    ];

    $this->postJson(route('midtrans.webhook.subscription'), $payload)->assertStatus(200);
    $this->postJson(route('midtrans.webhook.subscription'), $payload)->assertStatus(200);

    expect(Subscription::count())->toBe(1);
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('official subscription creation payload is acknowledged without mutations', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $payload = [
        'id'           => $sub->subscription_id,
        'name'         => 'SUBSCRIBE-' . time(),
        'status'       => 'active',
        'amount'       => '100000',
        'currency'     => 'IDR',
        'payment_type' => 'credit_card',
        'schedule'     => [
            'interval'          => 1,
            'interval_unit'     => 'month',
            'next_execution_at' => now()->addMonth()->toIso8601String(),
        ],
        'token'        => 'notification-must-not-be-saved',
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Donation::count())->toBe(0);
    expect(Subscription::count())->toBe(1);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    expect($sub->fresh()->status)->toBe('active');
    expect($sub->fresh()->token)->not->toBe('notification-must-not-be-saved');
});

test('official subscription creation payload for unknown subscription is acknowledged safely', function () {
    $payload = [
        'id'           => 'sub-unknown-notification-' . time(),
        'name'         => 'SUBSCRIBE-UNKNOWN',
        'status'       => 'active',
        'amount'       => '25000',
        'currency'     => 'IDR',
        'payment_type' => 'credit_card',
        'schedule'     => ['interval' => 1, 'interval_unit' => 'month'],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Subscription::count())->toBe(0);
    expect(Donation::count())->toBe(0);
});

test('duplicate subscription creation notification remains idempotent and mutation-free', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $payload = [
        'id'           => $sub->subscription_id,
        'name'         => 'SUBSCRIBE-DUPLICATE',
        'status'       => 'active',
        'amount'       => '100000',
        'currency'     => 'IDR',
        'payment_type' => 'credit_card',
        'schedule'     => ['interval' => 1, 'interval_unit' => 'month'],
    ];

    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(200);
    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(200);

    expect(Subscription::count())->toBe(1);
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('creation notification cannot increase campaign progress', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();

    $response = $this->postJson(route('midtrans.webhook.subscription'), [
        'id'           => $sub->subscription_id,
        'name'         => 'SUBSCRIBE-NO-PAYMENT',
        'status'       => 'active',
        'amount'       => '999999',
        'currency'     => 'IDR',
        'payment_type' => 'credit_card',
        'schedule'     => ['interval' => 1, 'interval_unit' => 'month'],
    ]);

    $response->assertStatus(200);
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 1. OFFICIAL FIXTURE: SUCCESSFUL CARD CHARGE (SIGNED)
// ============================================================

test('official fixture: successful credit card recurring charge creates donation and updates progress', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CC-CYCLE-001';

    expect((int) $campaign->terkumpul_nominal)->toBe(0);
    expect(Donation::count())->toBe(0);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'gross_amount'       => '100000.00',
        'payment_type'       => 'credit_card',
        'transaction_status' => 'settlement',
        'status_code'        => '200',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->order_id)->toBe($cycleOrderId);
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->is_recurring)->toBeTruthy();
    expect($donation->subscription_id)->toBe($sub->subscription_id);
    expect((int) $donation->nominal)->toBe(100000);

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(100000);
});

// ============================================================
// 2. OFFICIAL FIXTURE: TANPA SIGNATURE, TANPA ORDER_ID, TANPA GROSS_AMOUNT
// (Menggunakan transaction_id dan subscription.amount resmi Midtrans)
// ============================================================

test('official fixture: charge without signature, order_id, or gross_amount uses transaction_id and subscription.amount', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $txId = '48fd27f9-284d-48fc-87d0-a799e80682c4';

    // Mock Get Status API pada Midtrans v2 Status API dan Get Subscription untuk transaction_id
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $txId . '/status' => Http::response([
            'transaction_id'     => $txId,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'              => $sub->subscription_id,
            'name'            => 'SUBSCRIBE-1666778331',
            'status'          => 'active',
            'transaction_ids' => [$txId],
        ], 200),
    ]);

    // Persis format resmi Midtrans doc:
    // Tidak ada signature_key, tidak ada order_id, tidak ada transaction.gross_amount
    $payload = [
        'event_name' => 'subscription.charge',
        'subscription' => [
            'id'           => $sub->subscription_id,
            'name'         => 'SUBSCRIBE-1666778331',
            'amount'       => '100000',
            'currency'     => 'IDR',
            'payment_type' => 'credit_card',
            'status'       => 'active',
        ],
        'transaction' => [
            'transaction_id'     => $txId,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'status_message'     => 'Success, transaction found',
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->order_id)->toBe($txId);
    expect($donation->status)->toBe('terverifikasi');
    expect((int) $donation->nominal)->toBe(100000);

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(100000);
});

// ============================================================
// 3. OFFICIAL FIXTURE: SUCCESSFUL GOPAY CHARGE
// ============================================================

test('official fixture: successful gopay recurring charge creates donation and updates progress', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
        'payment_type' => 'gopay',
        'nominal'      => 50000,
        'frequency'    => 'mingguan',
    ]);
    $cycleOrderId = 'SUB-GOPAY-CYCLE-001';

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'gross_amount'       => '50000.00',
        'payment_type'       => 'gopay',
        'transaction_status' => 'settlement',
        'status_code'        => '200',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->order_id)->toBe($cycleOrderId);
    expect($donation->status)->toBe('terverifikasi');
    expect((int) $donation->nominal)->toBe(50000);
    expect($donation->frequency)->toBe('mingguan');

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(50000);
});

// ============================================================
// 4. OFFICIAL FIXTURE: FAILED CHARGE (status_code 411, token invalid)
// ============================================================

test('official fixture: failed recurring charge (411 token invalid) does not create donation or increase progress', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();

    $payload = [
        'event_name' => 'subscription.charge',
        'subscription' => [
            'id'           => $sub->subscription_id,
            'name'         => 'SUB-TEST-FAIL',
            'amount'       => '100000.00',
            'currency'     => 'IDR',
            'payment_type' => 'credit_card',
            'status'       => 'inactive',
        ],
        'transaction' => [
            'transaction_id'     => 'tx-fail-001',
            'order_id'           => 'SUB-FAIL-001',
            'status_code'        => '411',
            'status_message'     => 'Token id is missing, invalid, or timed out',
            'transaction_status' => 'deny',
            'gross_amount'       => '100000.00',
            'signature_key'      => makeRecurringSignature('SUB-FAIL-001', '411', '100000.00'),
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200);

    expect(Donation::count())->toBe(0);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 5. OFFICIAL FIXTURE: MALFORMED NOTIFICATION (400)
// ============================================================

test('official fixture: malformed notification without subscription id returns 400', function () {
    $malformed = [
        'event_name' => 'subscription.charge',
        'transaction' => [
            'status_code' => '200',
            'order_id'    => 'SUB-MAL-001',
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $malformed);
    $response->assertStatus(400)->assertJson(['message' => 'Invalid payload']);
    expect(Donation::count())->toBe(0);
});

test('official fixture: malformed notification without order or transaction id returns 400', function () {
    $malformed = [
        'event_name' => 'subscription.charge',
        'subscription' => [
            'id' => 'some-sub-id',
        ],
        'transaction' => [
            'status_code' => '200',
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $malformed);
    $response->assertStatus(400)->assertJson(['message' => 'Invalid payload']);
    expect(Donation::count())->toBe(0);
});

// ============================================================
// 6. SECURITY: FORGED SUBSCRIPTION.INACTIVE TANPA SIGNATURE
// ============================================================

test('security: forged subscription.inactive without signature is rejected with 403 if provider says active', function () {
    ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);

    // Provider melaporkan subscription di server Midtrans masih AKTIF
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'     => $sub->subscription_id,
            'status' => 'active',
        ], 200),
    ]);

    $payload = [
        'event_name' => 'subscription.inactive',
        'subscription' => [
            'id'     => $sub->subscription_id,
            'status' => 'inactive',
        ],
        'transaction' => [
            'status_code' => '200',
            'order_id'    => 'SUB-INACTIVE-001',
        ],
    ];

    // Request tanpa signature_key harus diverifikasi ke provider, dan ditolak jika provider status masih active
    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Unverified subscription event']);

    $sub->refresh();
    expect($sub->status)->toBe('active'); // Status lokal tidak berubah!
});

test('security: valid subscription.inactive verified by provider getSubscription successfully marks inactive', function () {
    ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);

    // Provider melaporkan subscription di server Midtrans memang SUDAH INACTIVE
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'     => $sub->subscription_id,
            'status' => 'inactive',
        ], 200),
    ]);

    $payload = [
        'event_name' => 'subscription.inactive',
        'subscription' => [
            'id'     => $sub->subscription_id,
            'status' => 'inactive',
        ],
        'transaction' => [
            'status_code' => '200',
            'order_id'    => 'SUB-INACTIVE-VALID-001',
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    $sub->refresh();
    expect($sub->status)->toBe('inactive');
});

// ============================================================
// 7. SECURITY: GETSUBSCRIPTION TIDAK DIGUNAKAN SEBAGAI BUKTI SETTLEMENT
// ============================================================

test('security: charge not found on provider is rejected with 403 even if subscription exists', function () {
    ['subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'FAKE-CHARGE-ORD-001';

    // Provider Status API mengembalikan 404 Not Found untuk charge ini
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'status_code'    => '404',
            'status_message' => 'Transaction not found',
        ], 404),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id'        => $cycleOrderId,
        'signature_key'   => null, // Tanpa signature
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Provider verification failed']);

    expect(Donation::count())->toBe(0);
});

// ============================================================
// 8. SECURITY: PROVIDER SETTLEMENT MILIK SUBSCRIPTION / DONASI LAIN (KONFLIK)
// ============================================================

test('security: order_id already used by another donation is rejected with conflict 409', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'CONFLICT-ORDER-001';

    // Buat donasi satu kali (non-recurring) yang sudah memakai order_id ini
    Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $sub->user_id,
        'nominal'               => 100000,
        'jenis'                 => 'uang',
        'order_id'              => $cycleOrderId,
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'subscription_id'       => null, // Donasi non-langganan
        'idempotency_key'       => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'order_id'           => $cycleOrderId,
            'currency'           => 'IDR',
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id'        => $cycleOrderId,
        'signature_key'   => null,
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(409);

    // Tetap hanya ada 1 donasi (tidak menduplikasi)
    expect(Donation::count())->toBe(1);
});

// ============================================================
// 9. SECURITY: PROVIDER AMOUNT MISMATCH & PROVIDER TIMEOUT
// ============================================================

test('security: settlement with different amount on provider is rejected with 422', function () {
    ['subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $cycleOrderId = 'SUB-DIFF-AMOUNT-001';

    // Provider melaporkan transaksi bernilai 1.000, bukan 100.000
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '1000.00',
            'order_id'           => $cycleOrderId,
            'currency'           => 'IDR',
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id'        => $cycleOrderId,
        'gross_amount'    => '100000.00', // Payload mengaku 100rb
        'signature_key'   => null,
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(422)->assertJson(['message' => 'Provider amount mismatch']);

    expect(Donation::count())->toBe(0);
});

test('security: provider API connection error returns 403', function () {
    ['subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-TIMEOUT-001';

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response(null, 500),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id'        => $cycleOrderId,
        'signature_key'   => null,
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403);
    expect(Donation::count())->toBe(0);
});

// ============================================================
// 10. SECURITY: CAPTURE TANPA FRAUD APPROVAL (CHALLENGE / NULL)
// ============================================================

test('security: capture without fraud approval (null) is not accepted as settlement', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CAPTURE-NULL-001';

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'capture',
        'fraud_status'       => null, // Tanpa fraud_status accept
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200);

    expect(Donation::count())->toBe(0);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

test('security: capture with fraud challenge is not accepted as settlement', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CAPTURE-CHALLENGE-001';

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'capture',
        'fraud_status'       => 'challenge',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200);

    expect(Donation::count())->toBe(0);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

// ============================================================
// 11. IDEMPOTENCY & DUPLICATE WEBHOOK (Double Counting Protection)
// ============================================================

test('idempotency: duplicate recurring notification does not double count', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-DUP-TEST-001';

    $payload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id'        => $cycleOrderId,
        'gross_amount'    => '100000.00',
    ]);

    $r1 = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $r1->assertStatus(200);

    $r2 = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $r2->assertStatus(200);

    expect(Donation::where('order_id', $cycleOrderId)->count())->toBe(1);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(100000);
});

// ============================================================
// 12. E4.3: CAPTURE RECONCILIATION & TRANSACTION LINKAGE TESTS
// ============================================================

test('e4.3: capture without fraud_status in notification is accepted when provider confirms accept', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CAP-RECON-001';

    // Provider Status API mengonfirmasi bahwa transaksi capture ini memang 'accept'
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_id'     => 'tx-cap-recon-001',
            'order_id'           => $cycleOrderId,
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
        ], 200),
    ]);

    // Payload resmi tanpa field fraud_status
    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'capture',
        'fraud_status'       => null, // notification tidak memuat fraud_status
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200)->assertJson(['message' => 'OK']);

    // Pembayaran diverifikasi melalui rekonsiliasi aktif dan donasi dicatat
    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->order_id)->toBe($cycleOrderId);
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->webhook_fraud_status)->toBe('accept');

    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(100000);
});

test('e4.3: capture without fraud_status is rejected when provider reports challenge or deny', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CAP-CHALLENGE-001';

    // Provider Status API mengonfirmasi bahwa transaksi capture ini 'challenge'
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_id'     => 'tx-cap-chal-001',
            'order_id'           => $cycleOrderId,
            'transaction_status' => 'capture',
            'fraud_status'       => 'challenge',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'capture',
        'fraud_status'       => null,
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(200);

    // Donasi TIDAK BOLEH dicatat
    expect(Donation::count())->toBe(0);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});

test('e4.3: order_id without valid subscription prefix is rejected with 403', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $unprefixedOrderId = 'RANDOM-ORDER-12345';

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $unprefixedOrderId,
        'transaction_status' => 'settlement',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Transaction ID not linked to subscription']);

    expect(Donation::count())->toBe(0);
});

test('e4.3: provider subscription transaction_ids cross-check rejects unlinked transaction with 403', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CYCLE-UNLINKED-001';

    // Provider mengembalikan daftar transaksi resmi milik subscription ini, tapi cycleOrderId tidak ada di daftar
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'              => $sub->subscription_id,
            'status'          => 'active',
            'name'            => 'SUB-TEST-VALID',
            'transaction_ids' => ['SUB-CYCLE-LEGIT-001', 'SUB-CYCLE-LEGIT-002'], // cycleOrderId tidak ada!
        ], 200),
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'order_id'           => $cycleOrderId,
            'currency'           => 'IDR',
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'settlement',
        'signature_key'      => null, // Tanpa signature untuk memicu provider deep reconciliation
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Transaction ID not linked to subscription']);

    expect(Donation::count())->toBe(0);
});

test('e4.3: provider currency mismatch (USD) is rejected with 422', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CYCLE-USD-001';

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'order_id'           => $cycleOrderId,
            'currency'           => 'USD', // Mata uang bukan IDR
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'settlement',
        'signature_key'      => null,
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(422)->assertJson(['message' => 'Invalid currency']);

    expect(Donation::count())->toBe(0);
});

// ============================================================
// 13. E4.3.1: SPECIFIC OWNERSHIP & FAIL-CLOSED TESTS
// ============================================================

test('e4.3.1: signed recurring settlement belonging to Subscription A cannot be used for Subscription B', function () {
    ['subscription' => $subA] = createActiveSubscription();
    ['subscription' => $subB] = createActiveSubscription();
    $cycleOrderId = 'SUB-CYCLE-A-ONLY-001';

    // Provider mengonfirmasi bahwa order ini hanya milik Subscription A
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subB->subscription_id => Http::response([
            'id'              => $subB->subscription_id,
            'status'          => 'active',
            'name'            => 'SUB-B-OFFICIAL',
            'transaction_ids' => ['SUB-CYCLE-B-ONLY-001'], // Tidak memuat cycle milik Sub A
        ], 200),
        'https://api.sandbox.midtrans.com/v2/' . $cycleOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'order_id'           => $cycleOrderId,
            'currency'           => 'IDR',
        ], 200),
    ]);

    // Request ditandatangani secara sah (signed), tapi menargetkan Subscription B
    $payload = officialRecurringPayload([
        'subscription_id'    => $subB->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'settlement',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Transaction ID not linked to subscription']);

    expect(Donation::count())->toBe(0);
});

test('e4.3.1: provider Get Subscription failure fails closed with 403 without creating donation', function () {
    ['subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CYCLE-ERR-001';

    // Provider Get Subscription mengembalikan HTTP 500 error
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'error_messages' => ['Midtrans Subscription upstream error'],
        ], 500),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'settlement',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Provider verification failed']);

    expect(Donation::count())->toBe(0);
});

// ============================================================
// 12. FINAL E4 SANDBOX SECURITY PREFLIGHT TESTS
// ============================================================

test('security: signed inactive notification when provider is still active is rejected with 403', function () {
    ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);

    // Provider melaporkan status subscription masih ACTIVE di server Midtrans
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'     => $sub->subscription_id,
            'status' => 'active',
        ], 200),
    ]);

    $orderId = 'SUB-INACT-SIGNED-001';
    $sig = makeRecurringSignature($orderId, '200', '100000.00');

    // Notification memiliki SIGNATURE VALID (signed), namun event-nya subscription.inactive
    $payload = [
        'event_name' => 'subscription.inactive',
        'subscription' => [
            'id'     => $sub->subscription_id,
            'status' => 'inactive',
        ],
        'transaction' => [
            'order_id'           => $orderId,
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'transaction_status' => 'settlement',
            'signature_key'      => $sig,
        ],
    ];

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Unverified subscription event']);

    $sub->refresh();
    expect($sub->status)->toBe('active'); // Status lokal tidak boleh berubah
});

test('security: recurring charge without transaction_ids or other ownership proof fails closed with 403', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription();
    $cycleOrderId = 'SUB-CYCLE-UNLINKED-001';

    // Provider getSubscription sukses, namun daftar transaction_ids KOSONG (tidak membuktikan transaksi ini milik sub)
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id'              => $sub->subscription_id,
            'name'            => 'SUB-CYCLE-UNLINKED',
            'status'          => 'active',
            'transaction_ids' => [], // Kosong! Bukti linkage resmi tidak ada
        ], 200),
    ]);

    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'settlement',
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
    $response->assertStatus(403)->assertJson(['message' => 'Transaction ID not linked to subscription']);

    // Pastikan tidak ada donasi yang dicatat dan campaign progress tidak bertambah
    expect(Donation::count())->toBe(0);
    $campaign->refresh();
    expect((int) $campaign->terkumpul_nominal)->toBe(0);
});
