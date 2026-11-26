<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY_12345');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY_12345');
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.min_amount', 1000);
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');

    Http::preventStrayRequests();
});

// ============================================================
// Helper: Buat campaign dan donatur untuk test subscription
// ============================================================

function createSubscriptionCampaign(array $attributes = []): Campaign
{
    $title = $attributes['judul'] ?? 'Campaign Donasi Rutin Test';
    $penerimaId = $attributes['user_id'] ?? User::factory()->penerima()->create()->id;

    return Campaign::create(array_merge([
        'user_id'           => $penerimaId,
        'judul'             => $title,
        'slug'              => $attributes['slug'] ?? (Str::slug($title) . '-' . rand(1000, 9999)),
        'deskripsi'         => 'Deskripsi campaign test subscription WeCare',
        'tipe_bantuan'      => 'uang',
        'target_nominal'    => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi'           => 'biasa',
        'tanggal_mulai'     => now()->subDay()->toDateString(),
        'tanggal_selesai'   => now()->addDays(30)->toDateString(),
        'status'            => 'aktif',
    ], $attributes));
}

function mockSubscriptionApiSuccess(array $overrides = [], string $savedToken = 'mock-saved-token-123'): void
{
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => $savedToken,
            'transaction_id'     => 'tx-init-test-123',
            'gross_amount'       => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response(array_merge([
            'id'     => 'sub-test-' . rand(1000, 9999),
            'name'   => 'SUB-1-1-abc12345',
            'amount' => '100000',
            'status' => 'active',
            'schedule' => [
                'interval'          => 1,
                'interval_unit'     => 'month',
                'next_execution_at' => now()->addMonth()->toIso8601String(),
            ],
            'metadata' => ['wecare_subscription_id' => 1],
        ], $overrides), 200),
    ]);
}

// ============================================================
// 1. PEMBUATAN SUBSCRIPTION BERHASIL (Credit Card)
// ============================================================

test('creates monthly credit card subscription successfully and stores encrypted token', function () {
    $savedToken = 'mock-saved-token-001';
    mockSubscriptionApiSuccess(['status' => 'active'], $savedToken);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $rawToken = 'mock-cc-token-001';

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => $rawToken,
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(201);
    $response->assertJsonFragment(['success' => true, 'frequency' => 'bulanan']);

    $sub = Subscription::where('user_id', $donatur->id)->where('campaign_id', $campaign->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->frequency)->toBe('bulanan');
    expect($sub->interval_unit)->toBe('month');
    expect((int) $sub->nominal)->toBe(100000);
    expect($sub->payment_type)->toBe('credit_card');
    expect($sub->status)->toBe('active');

    // Token storage: decrypted via Eloquent, tapi di DB raw harus terenkripsi
    expect($sub->token)->toBe($savedToken);
    expect($sub->token)->not->toBe($rawToken);
    $rawDbRow = DB::table('subscriptions')->where('id', $sub->id)->first();
    expect($rawDbRow->token)->not->toBe($savedToken); // data terenkripsi di DB

    // Token storage: disembunyikan dari array/JSON serialization
    expect(array_key_exists('token', $sub->toArray()))->toBeFalse();
});

test('creates weekly credit card subscription successfully', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => 'mock-weekly-saved-token',
            'transaction_id'     => 'tx-init-test-weekly',
            'gross_amount'       => '50000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id'     => 'sub-test-weekly-' . rand(1000, 9999),
            'amount' => '50000',
            'status' => 'active',
            'schedule' => [
                'interval'          => 1,
                'interval_unit'     => 'week',
                'next_execution_at' => now()->addWeek()->toIso8601String(),
            ],
        ], 200),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 50000,
            'is_recurring'      => '1',
            'frequency'         => 'mingguan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-cc-weekly-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(201);
    $response->assertJsonFragment(['success' => true, 'frequency' => 'mingguan']);

    $sub = Subscription::where('user_id', $donatur->id)->where('campaign_id', $campaign->id)->first();
    expect($sub)->not->toBeNull();
    expect($sub->frequency)->toBe('mingguan');
    expect($sub->interval_unit)->toBe('week');
    expect((int) $sub->nominal)->toBe(50000);
});

// ============================================================
// 2. SUBSCRIPTION NAME & HTTP IDEMPOTENCY-KEY HEADER VALIDATION
// ============================================================

test('validates subscription name conforms to Midtrans rules and sends Idempotency-Key header', function () {
    mockSubscriptionApiSuccess(['status' => 'active']);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $idemKey  = Str::uuid()->toString();

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-cc-token',
            'idempotency_key'   => $idemKey,
            'recurring_consent' => 'accepted',
        ]
    );

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($idemKey) {
        // Cek header Idempotency-Key
        $hasIdemHeader = $request->hasHeader('Idempotency-Key', substr($idemKey, 0, 36));

        // Cek batasan nama subscription Midtrans: max 40 chars, hanya [a-zA-Z0-9\-_~.]
        $name = $request['name'] ?? '';
        $validLength = strlen($name) > 0 && strlen($name) <= 40;
        $validChars  = (bool) preg_match('/^[a-zA-Z0-9\-_~.]+$/', $name);

        return $hasIdemHeader && $validLength && $validChars;
    });
});

// ============================================================
// 3. GOPAY SUBSCRIPTION AUDIT (Belum Tersedia Tanpa Account Linking)
// ============================================================

test('rejects gopay subscription because account linking is not yet integrated', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'gopay',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['payment_type']);
    $errorMsg = session('errors')->first('payment_type');
    expect($errorMsg)->toContain('tidak mendukung donasi rutin');
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 4. CONSENT VALIDATION (Wajib bernilai accepted, tidak boleh default)
// ============================================================

test('rejects is_recurring when recurring_consent is omitted', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'       => 100000,
            'is_recurring'  => '1',
            'frequency'     => 'bulanan',
            'payment_type'  => 'credit_card',
            'payment_token' => 'mock-token',
            // recurring_consent sengaja tidak dikirim
        ]
    );

    $response->assertSessionHasErrors(['recurring_consent']);
    expect(Subscription::count())->toBe(0);
});

test('rejects is_recurring when recurring_consent is false or 0', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'recurring_consent' => '0',
        ]
    );

    $response->assertSessionHasErrors(['recurring_consent']);
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 5. VALIDASI INPUT LAINNYA
// ============================================================

test('rejects is_recurring without frequency', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            // frequency sengaja tidak dikirim
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['frequency']);
    expect(Subscription::count())->toBe(0);
});

test('rejects unsupported payment type for recurring (bank_transfer)', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'bank_transfer',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['payment_type']);
    expect(Subscription::count())->toBe(0);
});

test('rejects missing payment_token for recurring', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'recurring_consent' => 'accepted',
            // payment_token sengaja tidak dikirim
        ]
    );

    $response->assertSessionHasErrors(['payment_token']);
    expect(Subscription::count())->toBe(0);
});

test('non-donatur cannot create subscription (403)', function () {
    $penerima = User::factory()->penerima()->create();
    $campaign = createSubscriptionCampaign(['user_id' => $penerima->id]);

    $response = $this->actingAs($penerima)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(403);
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 6. PROVIDER API FAILURE & SANITISASI ERROR
// ============================================================

test('provider API failure (500) returns safe error without exposing credentials', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'error_messages' => ['Internal server error'],
        ], 500),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['midtrans']);
    $errorMsg = session('errors')->first('midtrans');
    expect($errorMsg)->not->toContain('TEST_ONLY_SERVER_KEY');
    expect($errorMsg)->not->toContain('12345');
    expect(Subscription::count())->toBe(0);
});

test('provider API unauthorized (401) returns sanitized safe error', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'error_messages' => ['Access denied due to unauthorized transaction'],
        ], 401),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['midtrans']);
    $errorMsg = session('errors')->first('midtrans');
    expect($errorMsg)->not->toContain('401');
    expect($errorMsg)->not->toContain('Access denied');
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 7. IDEMPOTENCY
// ============================================================

test('idempotency reuses existing subscription with same key and same params', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $idemKey  = Str::uuid()->toString();

    $existingSub = Subscription::create([
        'subscription_id' => 'sub-existing-001',
        'user_id'         => $donatur->id,
        'campaign_id'     => $campaign->id,
        'nominal'         => 100000,
        'frequency'       => 'bulanan',
        'interval'        => 1,
        'interval_unit'   => 'month',
        'payment_type'    => 'credit_card',
        'token'           => 'mock-token',
        'status'          => 'active',
        'is_anonim'       => false,
        'idempotency_key' => $idemKey,
    ]);

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'idempotency_key'   => $idemKey,
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(200);
    $response->assertJsonFragment([
        'subscription_id' => 'sub-existing-001',
        'success'         => true,
    ]);

    expect(Subscription::where('idempotency_key', $idemKey)->count())->toBe(1);
});

test('idempotency rejects same key with different nominal', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $idemKey  = Str::uuid()->toString();

    Subscription::create([
        'subscription_id' => 'sub-existing-002',
        'user_id'         => $donatur->id,
        'campaign_id'     => $campaign->id,
        'nominal'         => 100000,
        'frequency'       => 'bulanan',
        'interval'        => 1,
        'interval_unit'   => 'month',
        'payment_type'    => 'credit_card',
        'token'           => 'mock-token',
        'status'          => 'active',
        'is_anonim'       => false,
        'idempotency_key' => $idemKey,
    ]);

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 200000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'mock-token',
            'idempotency_key'   => $idemKey,
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['idempotency_key']);
});

test('rejects raw credit card numbers passed as subscription token', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->post(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => '4111222233334444', // Raw card number!
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertSessionHasErrors(['midtrans']);
    $errorMsg = session('errors')->first('midtrans');
    expect($errorMsg)->toContain('Nomor kartu mentah dilarang');
});

test('chargeInitialCardWithSaveToken fails closed when merchant does not support one click', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'    => '412',
            'status_message' => 'Merchant does not support One Click / recurring payment',
        ], 412),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $donation = Donation::create([
        'campaign_id'     => $campaign->id,
        'user_id'         => $donatur->id,
        'jenis'           => 'uang',
        'nominal'         => 100000,
        'order_id'        => 'INIT-CHARGE-001',
        'status'          => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $service = app(\App\Services\MidtransPaymentService::class);

    expect(function () use ($service, $donation, $donatur, $campaign) {
        $service->chargeInitialCardWithSaveToken($donation, 'temp-token-123', $donatur, $campaign);
    })->toThrow(\App\Services\MidtransApiException::class, 'One Click / Saved Card');
});

// ============================================================
// 8. ONE-TIME DONATION TETAP BERFUNGSI (Tidak Ada Regresi)
// ============================================================

test('one-time donation still works with is_recurring=false (no regression)', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token'        => 'snap-test-token-onetme',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v3/redirection/snap-test-token-onetme',
        ], 200),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'      => 75000,
            'is_recurring' => '0',
        ]
    );

    $response->assertStatus(200);
    $response->assertJsonFragment(['snap_token' => 'snap-test-token-onetme']);

    expect(Donation::count())->toBe(1);
    $donation = Donation::first();
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect((int) $donation->nominal)->toBe(75000);
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 9. E4.3: INITIAL CARD CHARGE & 3DS AUTHENTICATION TESTS
// ============================================================

test('initial charge requiring 3DS challenge returns redirect_url and leaves subscription uncreated', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '201',
            'transaction_status' => 'pending',
            'redirect_url'       => 'https://api.sandbox.midtrans.com/v2/token/3ds/auth-xyz',
            'transaction_id'     => 'tx-3ds-challenge-01',
        ], 200),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-3ds-challenge',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(200);
    $response->assertJson([
        'success'      => true,
        'requires_3ds' => true,
        'redirect_url' => 'https://api.sandbox.midtrans.com/v2/token/3ds/auth-xyz',
    ]);

    // Record donasi initial charge berstatus pending awaiting_3ds
    $initDonation = Donation::where('user_id', $donatur->id)->first();
    expect($initDonation)->not->toBeNull();
    expect($initDonation->status)->toBe('menunggu_pembayaran');
    expect($initDonation->payment_status_detail)->toBe('awaiting_3ds');

    // Subscription BELUM boleh dibuat sebelum 3DS selesai dan terverifikasi
    expect(Subscription::count())->toBe(0);
});

test('initial charge denied by bank marks donation as failed and rejects subscription', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '202',
            'transaction_status' => 'deny',
            'fraud_status'       => 'deny',
            'gross_amount'       => '100000.00',
            'status_message'     => 'Transaction denied by issuing bank',
        ], 200),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-denied',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    $response->assertJsonFragment(['success' => false]);

    $initDonation = Donation::where('user_id', $donatur->id)->first();
    expect($initDonation)->not->toBeNull();
    expect($initDonation->status)->toBe('gagal');
    expect($initDonation->payment_status_detail)->toBe('charge_denied');

    expect(Subscription::count())->toBe(0);
});

test('initial charge gateway 412 missing saved_token_id fails closed', function () {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'    => '412',
            'status_message' => 'Merchant does not support recurring save_token_id',
        ], 412),
    ]);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-no-oneclick',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    expect(Subscription::count())->toBe(0);
});

test('initial charge prevents double-charging by scheduling start_time to next billing interval', function () {
    $savedToken = 'saved-token-double-charge-guard';
    mockSubscriptionApiSuccess(['status' => 'active'], $savedToken);

    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-xyz',
            'recurring_consent' => 'accepted',
        ]
    );

    // Verifikasi bahwa createSubscription dikirim dengan start_time di masa depan (bukan sekarang)
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        if ($request->url() === 'https://api.sandbox.midtrans.com/v1/subscriptions') {
            $startTime = $request['schedule']['start_time'] ?? '';
            $subDate = \Carbon\Carbon::parse($startTime);
            // Harus minimal 20 hari dari sekarang (1 bulan ke depan)
            return $subDate->greaterThan(now()->addDays(20));
        }
        return true;
    });

    // Donasi awal aktivasi sudah terverifikasi
    $initDonation = Donation::where('user_id', $donatur->id)->first();
    expect($initDonation->status)->toBe('terverifikasi');
    expect($initDonation->subscription_id)->not->toBeNull();
});

// ============================================================
// 10. E4.3.1: STATEFUL 3DS ACTIVATION & RECOVERY TESTS
// ============================================================

test('e4.3.1: initial charge pending -> 3ds success -> webhook settlement creates subscription exactly once', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // 1. Initial charge returns 3DS pending
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '201',
            'transaction_status' => 'pending',
            'redirect_url'       => 'https://api.sandbox.midtrans.com/v2/token/3ds/xyz',
            'transaction_id'     => 'tx-init-3ds-001',
        ], 200),
    ]);

    $res = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-3ds',
            'recurring_consent' => 'accepted',
        ]
    );
    $res->assertStatus(200)->assertJson(['requires_3ds' => true]);
    expect(Subscription::count())->toBe(0);

    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation->status)->toBe('menunggu_pembayaran');

    // 2. 3DS selesai, webhook notifikasi settlement diterima
    $savedToken = 'saved-token-from-3ds-webhook';
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id'           => $donation->order_id,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
            'saved_token_id'     => $savedToken,
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id'       => 'sub-after-3ds-001',
            'amount'   => '100000',
            'status'   => 'active',
            'schedule' => [
                'interval'          => 1,
                'interval_unit'     => 'month',
                'next_execution_at' => now()->addMonth()->toIso8601String(),
            ],
        ], 200),
    ]);

    $secret = config('services.midtrans.signature_secret_key');
    $sig = hash('sha512', $donation->order_id . '200' . '100000.00' . $secret);

    $webhookPayload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => $sig,
        'saved_token_id'     => $savedToken,
    ];

    $webhookRes = $this->postJson('/midtrans/webhook', $webhookPayload);
    $webhookRes->assertStatus(200);

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->subscription_id)->toBe('sub-after-3ds-001');

    expect(Subscription::count())->toBe(1);
    $sub = Subscription::first();
    expect($sub->subscription_id)->toBe('sub-after-3ds-001');
    expect($sub->token)->toBe($savedToken);
});

test('e4.3.1: 3ds failed does not create subscription', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '201',
            'transaction_status' => 'pending',
            'redirect_url'       => 'https://api.sandbox.midtrans.com/v2/token/3ds/xyz',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-3ds',
            'recurring_consent' => 'accepted',
        ]
    );

    $donation = Donation::where('user_id', $donatur->id)->first();

    $secret = config('services.midtrans.signature_secret_key');
    $sig = hash('sha512', $donation->order_id . '202' . '100000.00' . $secret);

    $webhookRes = $this->postJson('/midtrans/webhook', [
        'order_id'           => $donation->order_id,
        'status_code'        => '202',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'deny',
        'fraud_status'       => 'deny',
        'signature_key'      => $sig,
    ]);

    $webhookRes->assertStatus(200);
    $donation->refresh();
    expect($donation->status)->toBe('gagal');
    expect(Subscription::count())->toBe(0);
});

test('e4.3.1: duplicate 3ds callback and webhook does not double charge or create duplicate subscription', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '201',
            'transaction_status' => 'pending',
            'redirect_url'       => 'https://api.sandbox.midtrans.com/v2/token/3ds/xyz',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id'     => 'sub-dup-check-001',
            'amount' => '100000',
            'status' => 'active',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'saved_token_id'     => 'saved-tok-dup',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-3ds',
            'recurring_consent' => 'accepted',
        ]
    );

    $donation = Donation::where('user_id', $donatur->id)->first();
    $secret = config('services.midtrans.signature_secret_key');
    $sig = hash('sha512', $donation->order_id . '200' . '100000.00' . $secret);

    $webhookPayload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => $sig,
        'saved_token_id'     => 'saved-tok-dup',
    ];

    // Webhook 1
    $this->postJson('/midtrans/webhook', $webhookPayload)->assertStatus(200);

    // Webhook 2 (duplicate)
    $this->postJson('/midtrans/webhook', $webhookPayload)->assertStatus(200);

    // Endpoint verifikasi aktivasi (concurrent check)
    $this->actingAs($donatur)->postJson(route('donatur.donasi.verify-activation', $donation->order_id))->assertStatus(200);

    expect(Subscription::count())->toBe(1);
    expect(Donation::count())->toBe(1);
});

test('e4.3.1: initial charge success but create subscription fails leaves recoverable status without re-charging', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Initial charge berhasil, lalu Create Subscription ditolak definitif (422) sebelum dibuat, lalu sukses saat dipulihkan (sequence)
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => 'saved-tok-recov-001',
            'gross_amount'       => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::sequence()
            ->push(['error_messages' => ['Validation rejected before creation']], 422)
            ->push(['id' => 'sub-recovered-001', 'amount' => '100000', 'status' => 'active'], 200),
    ]);

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-recoverable',
            'recurring_consent' => 'accepted',
        ]
    );

    // Donasi awal tetap tersimpan dan dana terverifikasi (tidak dibatalkan, tidak ada charge ulang!)
    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation)->not->toBeNull();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('subscription_activation_failed_recoverable');
    expect(Subscription::count())->toBe(0);

    // Pemulihan (recovery): saat gateway langganan dipanggil kembali, panggilan kedua sukses (Section 8.1: bukti nominal terverifikasi eksplisit)
    $service = app(\App\Services\MidtransPaymentService::class);
    $recovery = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-recov-001', '100000.00');

    expect($recovery['success'])->toBeTrue();
    expect(Subscription::count())->toBe(1);
    $donation->refresh();
    expect($donation->subscription_id)->toBe('sub-recovered-001');
    expect($donation->payment_status_detail)->toBe('subscription_activated');
});

test('e4.3.1: initial capture without valid fraud_status is not immediately considered success', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Initial charge API mengembalikan capture tanpa fraud_status, provider status mengembalikan challenge
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            // fraud_status sengaja kosong
            'saved_token_id'     => 'saved-tok-chal',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'transaction_status' => 'capture',
            'fraud_status'       => 'challenge', // bukan accept
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
        ], 200),
    ]);

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-fraud-test',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 11. FINAL E4 SANDBOX SECURITY PREFLIGHT TESTS
// ============================================================

test('security: provider timeout during subscription creation marks recoverable and preserves verified donation without recharge', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Initial charge berhasil, tapi createSubscription mengalami ConnectionException (timeout)
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => 'saved-tok-timeout-001',
            'gross_amount'       => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timeout to Midtrans');
        },
    ]);

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-timeout',
            'recurring_consent' => 'accepted',
        ]
    );

    // Donasi awal tetap tersimpan dan dana terverifikasi (dana tidak hilang, tidak ada charge ulang!)
    // E5.2 4.3: Timeout tanpa ID provider menandai status ambigu untuk cegah POST ulang otomatis
    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation)->not->toBeNull();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('subscription_activation_ambiguous_manual_review');
    expect(Subscription::count())->toBe(0);

    // Percobaan pemulihan otomatis berikutnya WAJIB diblokir tanpa mengirim request ke provider
    $retry = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation->fresh(), 'saved-tok-timeout-001', '100000.00');
    expect($retry['success'])->toBeFalse();
    expect($retry['recoverable'])->toBeFalse();
    expect($retry['ambiguous'])->toBeTrue();
});

test('security: webhook with tampered saved_token_id but valid signature is rejected with 403', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Buat initial donation berstatus terverifikasi
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-TAMPER-' . time(),
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'settled',
    ]);

    // Provider memiliki saved_token_id resmi 'authentic-token-123'
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id'           => $donation->order_id,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
            'saved_token_id'     => 'authentic-token-123',
        ], 200),
    ]);

    $secret = config('services.midtrans.signature_secret_key');
    $sig = hash('sha512', $donation->order_id . '200' . '100000.00' . $secret);

    // Webhook payload memiliki signature valid untuk order dan amount,
    // TETAPI saved_token_id dimanipulasi menjadi 'forged-attacker-token'
    $tamperedPayload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => $sig,
        'saved_token_id'     => 'forged-attacker-token',
    ];

    $response = $this->postJson('/midtrans/webhook', $tamperedPayload);
    $response->assertStatus(403)->assertJson(['message' => 'Forged saved_token_id detected']);

    // Pastikan tidak ada subscription yang dibuat dengan token palsu
    expect(Subscription::count())->toBe(0);
});

test('security: webhook provider status with order_id or amount mismatch is rejected with 403', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-MISMATCH-' . time(),
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'settled',
    ]);

    // Provider mengembalikan order_id yang berbeda
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'order_id'           => 'DIFFERENT-ORDER-999',
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '100000.00',
            'currency'           => 'IDR',
            'saved_token_id'     => 'token-123',
        ], 200),
    ]);

    $secret = config('services.midtrans.signature_secret_key');
    $sig = hash('sha512', $donation->order_id . '200' . '100000.00' . $secret);

    $payload = [
        'order_id'           => $donation->order_id,
        'status_code'        => '200',
        'gross_amount'       => '100000.00',
        'transaction_status' => 'settlement',
        'fraud_status'       => 'accept',
        'signature_key'      => $sig,
        'saved_token_id'     => 'token-123',
    ];

    $response = $this->postJson('/midtrans/webhook', $payload);
    $response->assertStatus(403);
    expect(Subscription::count())->toBe(0);
});

test('security: concurrent activation request with activation_in_progress stops without create subscription', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => true,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-CONCURRENT-' . time(),
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'activation_in_progress',
        'idempotency_key'       => 'init-concurrent-key',
    ]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->activateSubscriptionFromInitialCharge($donation, 'saved-token-concurrent', '100000.00');

    expect($result['success'])->toBeFalse();
    expect($result['recoverable'])->toBeTrue();
    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
});

test('security: stale activation lease without provider subscription_id transitions to ambiguous_manual_review and blocks blind POST retry', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => true,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-STALE-' . time(),
        'status'                => 'terverifikasi',
        'payment_status_detail' => 'activation_in_progress',
        'idempotency_key'       => 'init-stale-key',
    ]);
    DB::table('donations')->where('id', $donation->id)->update([
        'updated_at' => now()->subMinutes(6),
    ]);
    $donation->refresh();

    Http::fake();

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation, 'saved-token-stale', '100000.00');

    expect($result['success'])->toBeFalse();
    expect($result['recoverable'])->toBeFalse();
    expect($result['ambiguous'])->toBeTrue();
    expect($donation->fresh()->payment_status_detail)->toBe('subscription_activation_ambiguous_manual_review');
    Http::assertNothingSent();
});

test('security: recovery without provider saved token stores null and safe status', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-NOTOKEN-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-no-saved-token-001',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-no-saved-token-001' => Http::response([
            'id'       => 'sub-no-saved-token-001',
            'amount'   => '100000',
            'currency' => 'IDR',
            'status'   => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            'metadata' => [],
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeTrue();
    $subscription = Subscription::first();
    expect($subscription->token)->toBeNull();
    expect($subscription->status)->toBe('active');
    expect($subscription->metadata['token_status'])->toBe('missing_saved_token');
});

test('security: encrypted subscription token longer than 255 characters is persisted', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $longToken = str_repeat('encrypted-provider-token-', 20);

    $subscription = Subscription::create([
        'subscription_id' => 'sub-long-encrypted-token',
        'user_id'         => $donatur->id,
        'campaign_id'     => $campaign->id,
        'nominal'         => 50000,
        'frequency'       => 'bulanan',
        'interval'        => 1,
        'interval_unit'   => 'month',
        'payment_type'    => 'credit_card',
        'token'           => $longToken,
        'status'          => 'active',
        'is_anonim'       => false,
        'idempotency_key' => 'long-token-' . Str::uuid()->toString(),
    ]);

    expect(strlen((string) $subscription->token))->toBeGreaterThan(255);
    expect($subscription->fresh()->token)->toBe($longToken);
});

test('security: recovery fails closed when provider subscription cannot be accessed', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-PROVIDER-DOWN-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-provider-down',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-provider-down' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Provider unavailable');
        },
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeFalse();
    expect($result['recoverable'])->toBeTrue();
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/v1/subscriptions');
    });
    $donation->refresh();
    expect($donation->subscription_id)->toBe('sub-provider-down');
});

test('security: recovery rejects mismatched provider nominal or currency', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-MISMATCH-VALUE-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-mismatch-value',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-mismatch-value' => Http::response([
            'id'       => 'sub-mismatch-value',
            'amount'   => '75000',
            'currency' => 'USD',
            'status'   => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => Http::response([
            'order_id'           => $donation->order_id,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '50000.00',
            'currency'           => 'IDR',
            'saved_token_id'     => 'saved-token-must-not-trigger-charge',
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
    expect($donation->fresh()->status)->toBe('terverifikasi');
    expect($donation->fresh()->payment_status_detail)->toBe('subscription_local_save_failed_recoverable');
    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && (str_contains($request->url(), '/v1/subscriptions')
                || str_contains($request->url(), '/v2/charge'));
    });
});

test('security: recovery rejects provider identity mismatch', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-MISMATCH-SUB-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-local-identity',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-local-identity' => Http::response([
            'id'       => 'sub-different-provider-identity',
            'amount'   => '100000',
            'currency' => 'IDR',
            'status'   => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
    expect($donation->fresh()->status)->toBe('terverifikasi');
    expect($donation->fresh()->payment_status_detail)->toBe('subscription_local_save_failed_recoverable');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && (str_contains($request->url(), '/v1/subscriptions')
            || str_contains($request->url(), '/v2/charge')));
});

test('security: recovery rejects provider interval mismatch', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-MISMATCH-INTERVAL-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-mismatch-interval',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-mismatch-interval' => Http::response([
            'id'       => 'sub-mismatch-interval',
            'amount'   => '50000',
            'currency' => 'IDR',
            'status'   => 'active',
            'schedule' => ['interval' => 2, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => Http::response([
            'order_id'           => $donation->order_id,
            'transaction_status' => 'settlement',
            'status_code'        => '200',
            'gross_amount'       => '50000.00',
            'currency'           => 'IDR',
            'saved_token_id'     => 'saved-token-no-charge',
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
    expect($donation->fresh()->status)->toBe('terverifikasi');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && (str_contains($request->url(), '/v1/subscriptions')
            || str_contains($request->url(), '/v2/charge')));
});

test('security: recovery rejects provider interval_unit mismatch', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 50000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-MISMATCH-UNIT-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-mismatch-unit',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-mismatch-unit' => Http::response([
            'id'       => 'sub-mismatch-unit',
            'amount'   => '50000',
            'currency' => 'IDR',
            'status'   => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && (str_contains($request->url(), '/v1/subscriptions')
            || str_contains($request->url(), '/v2/charge')));
});

test('security: recovery after provider create success but local save failed restores subscription from provider', function () {
    $donatur  = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Skenario: Provider Subscription API sudah sukses membuat 'sub-remote-success-001',
    // namun donasi tersimpan dengan status recoverable karena kegagalan lokal sementara
    $donation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-SUB-LOCALFAIL-' . time(),
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-remote-success-001',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
    ]);

    // Mock Get Subscription API dari provider untuk proses pemulihan data lokal
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-remote-success-001' => Http::response([
            'id'       => 'sub-remote-success-001',
            'amount'   => '100000',
            'currency' => 'IDR',
            'status'   => 'active',
            'saved_token_id' => 'saved-token-recovered-provider',
            'schedule' => [
                'interval'          => 1,
                'interval_unit'     => 'month',
                'next_execution_at' => now()->addMonth()->toIso8601String(),
            ],
            'metadata' => ['idempotency_key' => 'sub-recovered-idem-key'],
        ], 200),
    ]);

    $service = app(\App\Services\MidtransPaymentService::class);
    $result = $service->activateSubscriptionFromInitialCharge($donation);

    expect($result['success'])->toBeTrue();
    expect(Subscription::count())->toBe(1);

    $sub = Subscription::first();
    expect($sub->subscription_id)->toBe('sub-remote-success-001');
    expect($sub->status)->toBe('active');
    expect((int) $sub->nominal)->toBe(100000);

    $donation->refresh();
    expect($donation->payment_status_detail)->toBe('subscription_activated');
});

// ============================================================
// 11. E5.1.8C: INITIAL RECURRING & RECOVERY AMOUNT HARDENING
// ============================================================

test('e5.1.8c initial charge: rejects invalid or mismatched /v2/charge gross_amount without verifying donation or creating subscription', function (mixed $badChargeAmount) {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => 'saved-tok-should-not-activate',
            'transaction_id'     => 'tx-init-bad-amt',
            'gross_amount'       => $badChargeAmount,
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-token-bad-amt',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    expect(Subscription::count())->toBe(0);

    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation)->not->toBeNull();
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect($donation->payment_status_detail)->toBe('charge_amount_mismatch');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
})->with([
    'scientific notation string' => ['1e5'],
    'fractional non-zero' => ['100000.50'],
    'mismatched amount' => ['50000.00'],
    'missing amount' => [null],
    'whitespace padded' => [' 100000.00 '],
]);

test('e5.1.8c initial recurring outbound & recovery: invalid local nominal prevents outbound HTTP and invalid provider recovery amount fails closed', function () {
    Http::fake();
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    // 1. chargeInitialCardWithSaveToken rejects fractional local nominal before HTTP call
    $fracDonation = Donation::create([
        'campaign_id'     => $campaign->id,
        'user_id'         => $donatur->id,
        'jenis'           => 'uang',
        'nominal'         => '100000.99',
        'is_recurring'    => false,
        'frequency'       => 'bulanan',
        'order_id'        => 'INIT-FRAC-OUT-001',
        'status'          => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    expect(fn () => $service->chargeInitialCardWithSaveToken($fracDonation, 'tok-123', $donatur, $campaign))
        ->toThrow(\App\Services\MidtransApiException::class);

    // 2. createSubscription rejects fractional, oversized, or non-strict amounts before HTTP call
    expect(fn () => $service->createSubscription($donatur, $campaign, '100000.99', 'bulanan', 'credit_card', 'tok-123'))
        ->toThrow(\App\Services\MidtransApiException::class);
    expect(fn () => $service->createSubscription($donatur, $campaign, '1e5', 'bulanan', 'credit_card', 'tok-123'))
        ->toThrow(\App\Services\MidtransApiException::class);

    // 3. activateSubscriptionFromInitialCharge rejects fractional local nominal before HTTP call
    $fracRes = $service->activateSubscriptionFromInitialCharge($fracDonation, 'saved-tok-123');
    expect($fracRes['success'])->toBeFalse();
    Http::assertNothingSent();

    // 4. Recovery with invalid provider amount fails closed without creating Subscription
    $recoverableDonation = Donation::create([
        'campaign_id'           => $campaign->id,
        'user_id'               => $donatur->id,
        'jenis'                 => 'uang',
        'nominal'               => 100000,
        'is_recurring'          => false,
        'frequency'             => 'bulanan',
        'order_id'              => 'INIT-REC-BADAMT-001',
        'status'                => 'terverifikasi',
        'subscription_id'       => 'sub-rec-badamt-001',
        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
        'idempotency_key'       => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-rec-badamt-001' => Http::response([
            'id'       => 'sub-rec-badamt-001',
            'amount'   => '1e5',
            'currency' => 'IDR',
            'status'   => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $recRes = $service->activateSubscriptionFromInitialCharge($recoverableDonation);
    expect($recRes['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
});

// ============================================================
// 12. E5.1.8C.1: C1, C3, C4, C5 REGRESSION TESTS
// ============================================================

test('e5.1.8c.1 c1: initial capture reconciliation rejects missing, null, malformed, mismatched, or conflicting Status API evidence without falling back to charge amount', function (array $statusBody, string $chargeAmount) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            // fraud_status sengaja kosong -> memicu Status API reconciliation
            'saved_token_id'     => 'saved-tok-c1-001',
            'gross_amount'       => $chargeAmount,
        ], 200),
        'https://api.sandbox.midtrans.com/v2/*/status' => function (\Illuminate\Http\Client\Request $request) use ($statusBody) {
            preg_match('#/v2/([^/]+)/status#', $request->url(), $m);
            $body = $statusBody;
            if (array_key_exists('order_id', $body) && $body['order_id'] === '__MATCH__') {
                $body['order_id'] = $m[1];
            }
            return Http::response($body, 200);
        },
    ]);

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal'           => 100000,
            'is_recurring'      => '1',
            'frequency'         => 'bulanan',
            'payment_type'      => 'credit_card',
            'payment_token'     => 'temp-tok-c1',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
})->with([
    'missing status gross_amount' => [['order_id' => '__MATCH__', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'currency' => 'IDR'], '100000.00'],
    'explicit null status gross_amount' => [['order_id' => '__MATCH__', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => null, 'currency' => 'IDR'], '100000.00'],
    'malformed scientific status gross_amount' => [['order_id' => '__MATCH__', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '1e5', 'currency' => 'IDR'], '100000.00'],
    'mismatched status gross_amount' => [['order_id' => '__MATCH__', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '50000.00', 'currency' => 'IDR'], '100000.00'],
    'conflicting charge and status amounts' => [['order_id' => '__MATCH__', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '100000.00', 'currency' => 'IDR'], '50000.00'],
    'missing status order_id' => [['status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '100000.00', 'currency' => 'IDR'], '100000.00'],
    'mismatched status order_id' => [['order_id' => 'WRONG-ORDER-ID', 'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'gross_amount' => '100000.00', 'currency' => 'IDR'], '100000.00'],
]);

test('e5.1.8c.1 c1: initial capture reconciliation handles Status API timeout safely without verifying or creating subscription', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '200',
            'transaction_status' => 'capture',
            'saved_token_id' => 'saved-tok-timeout-c1',
            'gross_amount' => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/*/status' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Status API timeout');
        },
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal' => 100000, 'is_recurring' => '1', 'frequency' => 'bulanan',
            'payment_type' => 'credit_card', 'payment_token' => 'temp-tok-to', 'recurring_consent' => 'accepted',
        ]
    )->assertStatus(504);

    expect(Donation::where('user_id', $donatur->id)->first()->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
});

test('e5.1.8c.1 c1: initial capture reconciliation succeeds on valid capture-accept or settlement Status API evidence', function (string $remoteTxStatus) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '200',
            'transaction_status' => 'capture',
            'saved_token_id' => "saved-tok-ok-{$remoteTxStatus}",
            'gross_amount' => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/*/status' => function (\Illuminate\Http\Client\Request $request) use ($remoteTxStatus) {
            preg_match('#/v2/([^/]+)/status#', $request->url(), $m);
            return Http::response([
                'order_id' => $m[1],
                'status_code' => '200',
                'transaction_status' => $remoteTxStatus,
                'fraud_status' => $remoteTxStatus === 'capture' ? 'accept' : null,
                'gross_amount' => '100000.00',
                'currency' => 'IDR',
                'saved_token_id' => "saved-tok-ok-{$remoteTxStatus}",
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id' => "sub-c1-ok-{$remoteTxStatus}",
            'amount' => '100000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal' => 100000, 'is_recurring' => '1', 'frequency' => 'bulanan',
            'payment_type' => 'credit_card', 'payment_token' => "temp-tok-ok-{$remoteTxStatus}", 'recurring_consent' => 'accepted',
        ]
    )->assertStatus(201);

    expect(Subscription::count())->toBe(1);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(100000);
})->with([
    'capture accept' => ['capture'],
    'settlement' => ['settlement'],
]);

test('e5.1.8c.1 c3: initial charge failure transitions require verified exact amount and never downgrade verified donations', function (string $scenario, mixed $amount, string $expectedStatus) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    // Part A: Immediate /v2/charge failure paths
    $chargePayload = match ($scenario) {
        'valid_deny' => ['status_code' => '202', 'transaction_status' => 'deny', 'fraud_status' => 'deny', 'gross_amount' => $amount],
        'valid_cancel' => ['status_code' => '200', 'transaction_status' => 'cancel', 'gross_amount' => $amount],
        'valid_expire' => ['status_code' => '407', 'transaction_status' => 'expire', 'gross_amount' => $amount],
        'valid_capture_deny' => ['status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'deny', 'gross_amount' => $amount],
        'missing_amount' => ['status_code' => '202', 'transaction_status' => 'deny', 'fraud_status' => 'deny'],
        default => ['status_code' => '202', 'transaction_status' => 'deny', 'fraud_status' => 'deny', 'gross_amount' => $amount],
    };

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response($chargePayload, 200),
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal' => 100000, 'is_recurring' => '1', 'frequency' => 'bulanan',
            'payment_type' => 'credit_card', 'payment_token' => 'temp-tok-c3-' . $scenario, 'recurring_consent' => 'accepted',
        ]
    )->assertStatus(422);

    $immediateDonation = Donation::where('user_id', $donatur->id)->orderByDesc('id')->first();
    expect($immediateDonation->status)->toBe($expectedStatus);
    expect(Subscription::count())->toBe(0);
})->with([
    'valid deny' => ['valid_deny', '100000.00', 'gagal'],
    'valid cancel' => ['valid_cancel', '100000.00', 'gagal'],
    'valid expire' => ['valid_expire', '100000.00', 'gagal'],
    'valid capture fraud deny' => ['valid_capture_deny', '100000.00', 'gagal'],
    'missing amount' => ['missing_amount', null, 'menunggu_pembayaran'],
    'explicit null amount' => ['null_amount', null, 'menunggu_pembayaran'],
    'wrong amount' => ['wrong_amount', '50000.00', 'menunggu_pembayaran'],
    'scientific notation' => ['sci_amount', '1e5', 'menunggu_pembayaran'],
    'malformed array amount' => ['array_amount', ['100000.00'], 'menunggu_pembayaran'],
]);

test('e5.1.8c.1 c3: Status API failure verification in activation rejects unverified failures, fractional local nominal, and never downgrades verified donation', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    // 1. Pending donation with invalid/missing/mismatched Status API deny -> stays menunggu_pembayaran
    foreach ([null, '50000.00', '1e5', ['100000.00']] as $badAmt) {
        $d = Donation::create([
            'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
            'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
            'order_id' => 'INIT-C3-BAD-' . Str::random(6), 'status' => 'menunggu_pembayaran',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        $statusBody = ['order_id' => $d->order_id, 'status_code' => '202', 'transaction_status' => 'deny', 'fraud_status' => 'deny'];
        if ($badAmt !== null) {
            $statusBody['gross_amount'] = $badAmt;
        }
        Http::fake(['https://api.sandbox.midtrans.com/v2/' . $d->order_id . '/status' => Http::response($statusBody, 200)]);
        $res = $service->activateSubscriptionFromInitialCharge($d);
        expect($res['success'])->toBeFalse();
        expect($d->fresh()->status)->toBe('menunggu_pembayaran');
    }

    // 2. Pending donation with valid exact Status API deny -> marks gagal
    $validPending = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C3-OK-' . Str::random(6), 'status' => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    Http::fake(['https://api.sandbox.midtrans.com/v2/' . $validPending->order_id . '/status' => Http::response([
        'order_id' => $validPending->order_id, 'status_code' => '202', 'transaction_status' => 'deny',
        'fraud_status' => 'deny', 'gross_amount' => '100000.00',
    ], 200)]);
    expect($service->activateSubscriptionFromInitialCharge($validPending)['success'])->toBeFalse();
    expect($validPending->fresh()->status)->toBe('gagal');

    // 3. Fractional local nominal with deny -> rejected upfront, stays menunggu_pembayaran
    $fracPending = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => '100000.99', 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C3-FRAC-' . Str::random(6), 'status' => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    expect($service->activateSubscriptionFromInitialCharge($fracPending)['success'])->toBeFalse();
    expect($fracPending->fresh()->status)->toBe('menunggu_pembayaran');

    // 4. Already terverifikasi donation receiving Status API deny (e.g. missing saved token recheck) -> NEVER downgraded to gagal
    $verifiedDonation = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C3-VER-' . Str::random(6), 'status' => 'terverifikasi',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    Http::fake(['https://api.sandbox.midtrans.com/v2/' . $verifiedDonation->order_id . '/status' => Http::response([
        'order_id' => $verifiedDonation->order_id, 'status_code' => '202', 'transaction_status' => 'deny',
        'fraud_status' => 'deny', 'gross_amount' => '100000.00',
    ], 200)]);
    expect($service->activateSubscriptionFromInitialCharge($verifiedDonation)['success'])->toBeFalse();
    expect($verifiedDonation->fresh()->status)->toBe('terverifikasi');
    expect(Subscription::count())->toBe(0);
});

test('e5.1.8c.1 c4: locked-record amount revalidation prevents stale or mid-flight fractional nominal verification and activation', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    // 1. Mid-flight nominal change during /v2/charge -> locked verification aborts without verifying or activating
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => function () use ($donatur) {
            $latest = Donation::where('user_id', $donatur->id)->orderByDesc('id')->first();
            DB::table('donations')->where('id', $latest->id)->update(['nominal' => '100000.99']);
            return Http::response([
                'status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept',
                'saved_token_id' => 'saved-tok-c4-1', 'gross_amount' => '100000.00',
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal' => 100000, 'is_recurring' => '1', 'frequency' => 'bulanan',
            'payment_type' => 'credit_card', 'payment_token' => 'temp-tok-c4-1', 'recurring_consent' => 'accepted',
        ]
    )->assertStatus(422);

    $d1 = Donation::where('user_id', $donatur->id)->orderByDesc('id')->first();
    expect($d1->status)->toBe('menunggu_pembayaran');
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));

    // 2. Mid-flight nominal change during Status API verification inside activateSubscriptionFromInitialCharge
    $d2 = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C4-STAT-' . Str::random(6), 'status' => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $d2->order_id . '/status' => function () use ($d2) {
            DB::table('donations')->where('id', $d2->id)->update(['nominal' => '200000.00']);
            return Http::response([
                'order_id' => $d2->order_id, 'status_code' => '200', 'transaction_status' => 'settlement',
                'gross_amount' => '100000.00', 'currency' => 'IDR', 'saved_token_id' => 'saved-tok-c4-2',
            ], 200);
        },
    ]);
    $res2 = $service->activateSubscriptionFromInitialCharge($d2);
    expect($res2['success'])->toBeFalse();
    expect($d2->fresh()->status)->toBe('menunggu_pembayaran');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));

    // 3. Mid-flight nominal change between createSubscription response and Step 6 locked local persistence
    $d3 = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C4-PERSIST-' . Str::random(6), 'status' => 'terverifikasi',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    $postSubCalls = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => function () use ($d3, &$postSubCalls) {
            $postSubCalls++;
            DB::table('donations')->where('id', $d3->id)->update(['nominal' => '200000.00']);
            return Http::response([
                'id' => 'sub-c4-persist-001', 'amount' => '100000', 'currency' => 'IDR', 'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/sub-c4-persist-001' => Http::response([
            'id' => 'sub-c4-persist-001', 'amount' => '100000', 'currency' => 'IDR', 'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $res3 = $service->activateSubscriptionFromInitialCharge($d3, 'saved-tok-c4-3', '100000.00');
    expect($res3['success'])->toBeFalse();
    expect($res3['recoverable'])->toBeTrue();
    expect($d3->fresh()->subscription_id)->toBe('sub-c4-persist-001');
    expect($d3->fresh()->payment_status_detail)->toBe('subscription_local_amount_mismatch_recoverable');
    expect(Subscription::count())->toBe(0);

    // Retry uses GET recovery and does NOT duplicate POST /v1/subscriptions!
    $retry3 = $service->activateSubscriptionFromInitialCharge($d3->fresh(), 'saved-tok-c4-3');
    expect($retry3['success'])->toBeFalse();
    expect($postSubCalls)->toBe(1);
});

test('e5.1.8c.1 c5: createSubscription response amount verification accepts equivalent 100000.00 and recovers safely on missing, null, mismatched, or malformed response amounts without duplicate POST', function (mixed $returnedAmount, bool $shouldSucceed) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang',
        'nominal' => 100000, 'is_recurring' => false, 'frequency' => 'bulanan',
        'order_id' => 'INIT-C5-' . Str::random(6), 'status' => 'terverifikasi',
        'payment_status_detail' => 'settled', 'idempotency_key' => Str::uuid()->toString(),
    ]);

    $subId = 'sub-c5-' . Str::random(6);
    $createBody = ['id' => $subId, 'status' => 'active', 'currency' => 'IDR', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']];
    if ($returnedAmount !== '__OMIT__') {
        $createBody['amount'] = $returnedAmount;
    }

    $postCalls = 0;
    $getCalls = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => function () use (&$postCalls, $createBody) {
            $postCalls++;
            return Http::response($createBody, 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => function () use (&$getCalls, $subId) {
            $getCalls++;
            return Http::response([
                'id' => $subId, 'amount' => '100000', 'currency' => 'IDR', 'status' => 'active',
                'saved_token_id' => 'saved-tok-c5-recov',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
            ], 200);
        },
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-c5', '100000.00');

    if ($shouldSucceed) {
        expect($res['success'])->toBeTrue();
        expect(Subscription::count())->toBe(1);
        expect($postCalls)->toBe(1);
        return;
    }

    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeTrue();
    expect($postCalls)->toBe(1);
    expect(Subscription::count())->toBe(0);
    expect($donation->fresh()->status)->toBe('terverifikasi');
    expect($donation->fresh()->subscription_id)->toBe($subId);
    expect($donation->fresh()->payment_status_detail)->toBe('subscription_response_amount_unverified_recoverable');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/disable') || str_contains($req->url(), '/cancel'));

    // Retry verifies via GET /v1/subscriptions/{id} WITHOUT duplicating POST /v1/subscriptions
    $retry = $service->activateSubscriptionFromInitialCharge($donation->fresh(), 'saved-tok-c5');
    expect($retry['success'])->toBeTrue();
    expect($postCalls)->toBe(1);
    expect($getCalls)->toBe(1);
    expect(Subscription::count())->toBe(1);
})->with([
    'equivalent 100000.00' => ['100000.00', true],
    'mismatched 50000' => ['50000', false],
    'missing amount' => ['__OMIT__', false],
    'explicit null amount' => [null, false],
    'fractional 100000.50' => ['100000.50', false],
    'scientific 1e5' => ['1e5', false],
    'malformed array' => [['100000'], false],
]);

// ============================================================
// E5.1.8D CHECKPOINT D0 — CURRENCY & VERIFIED SNAPSHOT GUARD
// ============================================================

test('D0: validateProviderFailure rejects explicit non-IDR and malformed currency types while allowing absent or null currency', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D0-CURR-' . Str::random(6),
        'status' => 'menunggu_pembayaran',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $base = [
        'order_id' => $donation->order_id,
        'status_code' => '202',
        'transaction_status' => 'deny',
        'gross_amount' => '100000.00',
    ];

    // Compatible absent / null / explicit IDR
    expect($service->validateProviderFailure($base, $donation))->toBeTrue();
    expect($service->validateProviderFailure(array_merge($base, ['currency' => null]), $donation))->toBeTrue();
    expect($service->validateProviderFailure(array_merge($base, ['currency' => 'IDR']), $donation))->toBeTrue();
    expect($service->validateProviderFailure(array_merge($base, ['currency' => 'idr']), $donation))->toBeTrue();

    // Explicit invalid / non-IDR / malformed runtime types must fail closed
    foreach (['USD', '', '   ', ' IDR ', 0, 123, false, true, [], ['IDR']] as $badCurrency) {
        expect($service->validateProviderFailure(
            array_merge($base, ['currency' => $badCurrency]),
            $donation
        ))->toBeFalse();
    }
});

test('D0: capture with fraud_status deny rejects non-IDR and malformed currency without marking donation gagal', function (mixed $badCurrency) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D0-CAPDENY-' . Str::random(6),
        'status' => 'menunggu_pembayaran',
        'payment_status_detail' => 'awaiting_3ds',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => Http::response([
            'order_id' => $donation->order_id,
            'status_code' => '200',
            'transaction_status' => 'capture',
            'fraud_status' => 'deny',
            'gross_amount' => '100000.00',
            'currency' => $badCurrency,
        ], 200),
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation);
    expect($res['success'])->toBeFalse();
    expect($donation->fresh()->status)->toBe('menunggu_pembayaran');
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
})->with([
    'USD' => ['USD'],
    'empty string' => [''],
    'false bool' => [false],
    'zero int' => [0],
    'array' => [['IDR']],
]);

test('D0: initial charge failure controller rejects non-IDR and malformed currency without marking donation gagal', function (mixed $badCurrency) {
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '202',
            'transaction_status' => 'deny',
            'fraud_status' => 'deny',
            'gross_amount' => '100000.00',
            'currency' => $badCurrency,
            'status_message' => 'Denied',
        ], 200),
    ]);

    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();

    $response = $this->actingAs($donatur)->postJson(
        route('donatur.donasi.uang', $campaign->slug),
        [
            'nominal' => 100000,
            'is_recurring' => '1',
            'frequency' => 'bulanan',
            'payment_type' => 'credit_card',
            'payment_token' => 'temp-token-bad-curr',
            'recurring_consent' => 'accepted',
        ]
    );

    $response->assertStatus(422);
    $initDonation = Donation::where('user_id', $donatur->id)->first();
    expect($initDonation)->not->toBeNull();
    expect($initDonation->status)->toBe('menunggu_pembayaran');
    expect($initDonation->payment_status_detail)->toBe('charge_failure_unverified');
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
})->with([
    'USD' => ['USD'],
    'empty string' => [''],
    'false bool' => [false],
    'zero int' => [0],
    'array' => [['IDR']],
]);

test('D0: activation rejects stale verified Donation model when DB nominal changed to another valid whole-IDR amount with zero subscription POST', function () {
    Http::fake();

    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $staleDonation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D0-STALE-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    // DB nominal changes to another valid whole-IDR amount while caller still holds stale $staleDonation (nominal 100000)
    DB::table('donations')->where('id', $staleDonation->id)->update(['nominal' => 200000]);

    $res = $service->activateSubscriptionFromInitialCharge($staleDonation, 'saved-tok-stale-1', '100000.00');
    expect($res['success'])->toBeFalse();
    expect($res['subscription'])->toBeNull();
    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
});

test('D0: activation rejects nominal mutation between Status API verification and guard acquisition with zero subscription POST', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D0-RACE-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => function () use ($donation) {
            // Simulate concurrent DB nominal drift right when Status API completes before Step 4 guard lock
            DB::table('donations')->where('id', $donation->id)->update(['nominal' => 200000]);

            return Http::response([
                'order_id' => $donation->order_id,
                'status_code' => '200',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'gross_amount' => '100000.00',
                'currency' => 'IDR',
                'saved_token_id' => 'saved-tok-d0-race',
            ], 200);
        },
    ]);

    // No savedTokenId passed -> runs Step 2 Status API (verified 100000.00), then Step 4 sees DB nominal 200000 -> aborts!
    $res = $service->activateSubscriptionFromInitialCharge($donation);
    expect($res['success'])->toBeFalse();
    expect($res['subscription'])->toBeNull();
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
});

test('D0: activation rejects mismatched explicit verifiedPaymentAmount before any outbound request', function () {
    Http::fake();

    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D0-EXPLICIT-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-d0-exp', '200000.00');
    expect($res['success'])->toBeFalse();
    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
});

// ============================================================
// E5.1.8D D6: CROSS-SERVICE RECOVERY PROTECTION AGAINST MANAGED SUBSCRIPTIONS
// ============================================================

test('E5.1.8D: d6 recovery does not reactivate cancelled subscription or erase cancellation metadata', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-d6-cancel-' . Str::random(6);
    $confirmedAt = '2026-11-25T16:39:06+00:00';

    $sub = Subscription::create([
        'subscription_id' => $subId,
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-d6-cancel',
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'idempotency_key' => Str::uuid()->toString(),
        'metadata' => [
            'token_status' => 'verified_saved_token',
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'inactive',
                'confirmed_at' => $confirmedAt,
            ],
        ],
    ]);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D6-CAN-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activated',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake();

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-d6-cancel');
    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    Http::assertNothingSent();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->metadata['cancellation']['confirmed_at'])->toBe($confirmedAt);
});

test('E5.1.8D: d6 recovery does not overwrite paused reconciliation or actively guarded subscription states', function (string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-d6-state-' . Str::random(6);

    $attrs = [
        'subscription_id' => $subId,
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-d6-state',
        'idempotency_key' => Str::uuid()->toString(),
        'metadata' => ['token_status' => 'verified_saved_token'],
    ];

    if ($scenario === 'paused') {
        $attrs['status'] = 'inactive';
        $attrs['management_state'] = 'paused';
    } elseif ($scenario === 'reconciliation') {
        $attrs['status'] = 'active';
        $attrs['management_state'] = 'reconciliation';
        $attrs['metadata']['last_management_operation'] = 'pause';
        $attrs['metadata']['management_reconciliation'] = 'provider_status_pending';
    } else {
        $attrs['status'] = 'active';
        $attrs['management_state'] = 'active';
        $attrs['operation_guard_owner'] = 'active-mgmt-owner';
        $attrs['operation_guard_at'] = now()->subMinute();
    }

    $sub = Subscription::create($attrs);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D6-STATE-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_failed_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake();

    $res = $service->activateSubscriptionFromInitialCharge($donation);
    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    Http::assertNothingSent();

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe($attrs['management_state']);
    expect($fresh->status)->toBe($attrs['status']);
    if ($scenario === 'guarded') {
        expect($fresh->operation_guard_owner)->toBe('active-mgmt-owner');
    }
})->with([
    'paused' => ['paused'],
    'reconciliation' => ['reconciliation'],
    'actively guarded' => ['guarded'],
]);

test('E5.1.8D: d6 recovery does not link or overwrite subscription belonging to another user or campaign', function () {
    $donatur1 = User::factory()->donatur()->create();
    $donatur2 = User::factory()->donatur()->create();
    $campaign1 = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-d6-other-' . Str::random(6);

    $foreignSub = Subscription::create([
        'subscription_id' => $subId,
        'user_id' => $donatur2->id,
        'campaign_id' => $campaign1->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-foreign',
        'status' => 'active',
        'management_state' => null,
        'idempotency_key' => Str::uuid()->toString(),
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);

    $donation = Donation::create([
        'campaign_id' => $campaign1->id,
        'user_id' => $donatur1->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D6-FOREIGN-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_failed_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => Http::response([
            'id' => $subId,
            'amount' => '100000',
            'currency' => 'IDR',
            'status' => 'active',
            'saved_token_id' => 'saved-tok-foreign',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
        ], 200),
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation);
    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    expect((int) $foreignSub->fresh()->user_id)->toBe($donatur2->id);
    expect($donation->fresh()->payment_status_detail)->toBe('subscription_activation_failed_recoverable');
});

test('E5.1.8D: d6 recovery still succeeds for genuinely missing local subscription record', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-d6-missing-' . Str::random(6);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-D6-MISS-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_response_amount_unverified_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => Http::response([
            'id' => $subId,
            'amount' => '100000',
            'currency' => 'IDR',
            'status' => 'active',
            'saved_token_id' => 'saved-tok-d6-ok',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
        ], 200),
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation);
    expect($res['success'])->toBeTrue();
    expect($res['subscription'])->not->toBeNull();
    expect($res['subscription']->subscription_id)->toBe($subId);
    expect(Subscription::where('subscription_id', $subId)->count())->toBe(1);
});

// ============================================================
// E5.1 FINAL SIGN-OFF: F1, F2, F3, F4 & SECTION 8.1 TESTS
// ============================================================

test('E5.1: F1 step 3 idempotency shortcut enforces management state protection and identity consistency', function (string $scenario) {
    Http::fake();

    $donatur = User::factory()->donatur()->create();
    $otherUser = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $otherCampaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $sharedKey = Str::uuid()->toString();
    $subId = 'sub-f1-s3-' . Str::random(6);

    $subAttrs = [
        'subscription_id' => $subId,
        'user_id' => $scenario === 'foreign_owner' ? $otherUser->id : $donatur->id,
        'campaign_id' => $scenario === 'wrong_campaign' ? $otherCampaign->id : $campaign->id,
        'nominal' => $scenario === 'amount_mismatch' ? 200000 : 100000,
        'frequency' => $scenario === 'wrong_frequency' ? 'mingguan' : 'bulanan',
        'interval' => 1,
        'interval_unit' => $scenario === 'wrong_frequency' ? 'week' : 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-f1-s3',
        'status' => in_array($scenario, ['paused', 'cancelled'], true) ? 'inactive' : 'active',
        'management_state' => match ($scenario) {
            'paused' => 'paused',
            'cancelled' => 'cancelled',
            'reconciliation' => 'reconciliation',
            'guarded', 'active' => 'active',
            default => null,
        },
        'operation_guard_owner' => $scenario === 'guarded' ? 'mgmt-owner-f1' : null,
        'operation_guard_at' => $scenario === 'guarded' ? now()->subMinute() : null,
        'idempotency_key' => $sharedKey,
        'metadata' => $scenario === 'cancelled'
            ? ['token_status' => 'verified_saved_token', 'cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']]
            : ['token_status' => 'verified_saved_token'],
    ];

    $existingSub = Subscription::create($subAttrs);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-F1-S3-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => 'init_' . $sharedKey,
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-f1-s3', '100000.00');
    Http::assertNothingSent();

    if ($scenario === 'active') {
        expect($res['success'])->toBeTrue();
        expect($res['subscription']?->subscription_id)->toBe($subId);
        expect($donation->fresh()->subscription_id)->toBe($subId);
        expect($donation->fresh()->payment_status_detail)->toBe('subscription_activated');
        return;
    }

    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    expect($res['subscription'])->toBeNull();
    expect($donation->fresh()->subscription_id)->toBeNull();
    expect($donation->fresh()->payment_status_detail)->toBe('settled');
    expect($existingSub->fresh()->management_state)->toBe($subAttrs['management_state']);
    expect($existingSub->fresh()->status)->toBe($subAttrs['status']);
})->with([
    'active' => ['active'],
    'paused' => ['paused'],
    'cancelled' => ['cancelled'],
    'reconciliation' => ['reconciliation'],
    'guarded' => ['guarded'],
    'foreign_owner' => ['foreign_owner'],
    'wrong_campaign' => ['wrong_campaign'],
    'wrong_frequency' => ['wrong_frequency'],
    'amount_mismatch' => ['amount_mismatch'],
]);

test('E5.1: F1 step 4 already_active shortcut blocks non-active protected and conflicting records without duplicate POST', function (string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $otherUser = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $otherCampaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-f1-s4-' . Str::random(6);

    $subAttrs = [
        'subscription_id' => $subId,
        'user_id' => $scenario === 'foreign_owner' ? $otherUser->id : $donatur->id,
        'campaign_id' => $scenario === 'wrong_campaign' ? $otherCampaign->id : $campaign->id,
        'nominal' => $scenario === 'amount_mismatch' ? 200000 : 100000,
        'frequency' => $scenario === 'wrong_frequency' ? 'mingguan' : 'bulanan',
        'interval' => 1,
        'interval_unit' => $scenario === 'wrong_frequency' ? 'week' : 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-f1-s4',
        'status' => in_array($scenario, ['paused', 'cancelled'], true) ? 'inactive' : 'active',
        'management_state' => match ($scenario) {
            'paused' => 'paused',
            'cancelled' => 'cancelled',
            'reconciliation' => 'reconciliation',
            'guarded', 'active' => 'active',
            default => null,
        },
        'operation_guard_owner' => $scenario === 'guarded' ? 'mgmt-owner-f1-s4' : null,
        'operation_guard_at' => $scenario === 'guarded' ? now()->subMinute() : null,
        'idempotency_key' => 'different-sub-key-' . Str::random(8),
        'metadata' => $scenario === 'cancelled'
            ? ['token_status' => 'verified_saved_token', 'cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']]
            : ['token_status' => 'verified_saved_token'],
    ];

    $existingSub = Subscription::create($subAttrs);

    // Create Donation with subscription_id already set AFTER bypassing Step 1 by testing Step 4 directly via empty initial subscription_id
    // updated right before Step 4 transaction — simulated cleanly via Status API callback when no savedTokenId is passed!
    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-F1-S4-' . Str::random(6),
        'subscription_id' => null,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => 'init_' . Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => function () use ($donation, $subId) {
            // Concurrent process links subscription_id between Step 1 and Step 4 guard acquisition
            DB::table('donations')->where('id', $donation->id)->update(['subscription_id' => $subId]);
            return Http::response([
                'order_id' => $donation->order_id,
                'status_code' => '200',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'gross_amount' => '100000.00',
                'currency' => 'IDR',
                'saved_token_id' => 'saved-tok-f1-s4',
            ], 200);
        },
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation);
    Http::assertNotSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), '/v1/subscriptions'));

    if ($scenario === 'active') {
        expect($res['success'])->toBeTrue();
        expect($res['subscription']?->subscription_id)->toBe($subId);
        return;
    }

    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    expect($res['subscription'])->toBeNull();
    expect($existingSub->fresh()->management_state)->toBe($subAttrs['management_state']);
    expect($existingSub->fresh()->status)->toBe($subAttrs['status']);
})->with([
    'active' => ['active'],
    'paused' => ['paused'],
    'cancelled' => ['cancelled'],
    'reconciliation' => ['reconciliation'],
    'guarded' => ['guarded'],
    'foreign_owner' => ['foreign_owner'],
    'wrong_campaign' => ['wrong_campaign'],
    'wrong_frequency' => ['wrong_frequency'],
    'amount_mismatch' => ['amount_mismatch'],
]);

test('E5.1: F2 checkout duplicate-key recovery verifies ownership, parameters, missing link, and management states', function (string $scenario) {
    Http::fake();

    $owner = User::factory()->donatur()->create();
    $otherUser = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $otherCampaign = createSubscriptionCampaign();
    $checkoutKey = Str::uuid()->toString();

    $subId = 'sub-f2-' . Str::random(6);
    $createSub = $scenario !== 'missing_subscription';

    if ($createSub) {
        Subscription::create([
            'subscription_id' => $subId,
            'user_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'nominal' => 100000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'saved-tok-f2',
            'status' => in_array($scenario, ['paused', 'cancelled'], true) ? 'inactive' : 'active',
            'management_state' => in_array($scenario, ['paused', 'cancelled'], true) ? $scenario : 'active',
            // Different idempotency_key on Subscription forces QueryException duplicate-key path on Donation.idempotency_key!
            'idempotency_key' => 'other-sub-key-' . Str::random(8),
            'metadata' => $scenario === 'cancelled'
                ? ['token_status' => 'verified_saved_token', 'cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']]
                : ['token_status' => 'verified_saved_token'],
        ]);
    }

    Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-F2-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activated',
        'idempotency_key' => "init_{$checkoutKey}",
    ]);

    $actingUser = $scenario === 'different_user' ? $otherUser : $owner;
    $targetCampaign = $scenario === 'different_campaign' ? $otherCampaign : $campaign;
    $reqNominal = $scenario === 'different_amount' ? 200000 : 100000;
    $reqFrequency = $scenario === 'different_frequency' ? 'mingguan' : 'bulanan';

    $response = $this->actingAs($actingUser)->postJson(
        route('donatur.donasi.uang', $targetCampaign->slug),
        [
            'nominal' => $reqNominal,
            'is_recurring' => '1',
            'frequency' => $reqFrequency,
            'payment_type' => 'credit_card',
            'payment_token' => 'temp-tok-f2',
            'recurring_consent' => 'accepted',
            'idempotency_key' => $checkoutKey,
        ]
    );

    Http::assertNothingSent();

    if (in_array($scenario, ['different_user', 'different_campaign', 'different_amount', 'different_frequency'], true)) {
        $response->assertStatus(422)->assertJson(['success' => false]);
        expect($response->getContent())->not->toContain($subId);
        return;
    }

    if ($scenario === 'missing_subscription') {
        $response->assertStatus(409)->assertJson(['success' => false]);
        return;
    }

    $response->assertStatus(200)->assertJson([
        'success' => true,
        'subscription_id' => $subId,
        'status' => in_array($scenario, ['paused', 'cancelled'], true) ? 'inactive' : 'active',
        'management_state' => $scenario === 'same_user_active' ? 'active' : $scenario,
    ]);

    if ($scenario === 'paused') {
        expect($response->json('message'))->toContain('sedang dijeda');
    } elseif ($scenario === 'cancelled') {
        expect($response->json('message'))->toContain('telah dibatalkan');
    }
})->with([
    'same user active retry' => ['same_user_active'],
    'different user rejected' => ['different_user'],
    'different campaign rejected' => ['different_campaign'],
    'different amount rejected' => ['different_amount'],
    'different frequency rejected' => ['different_frequency'],
    'missing linked subscription' => ['missing_subscription'],
    'paused existing state accurate' => ['paused'],
    'cancelled existing state accurate' => ['cancelled'],
]);

test('E5.1: F3 recovery respects management guard owner even when lease is expired and aborts on ownership transfer during GET', function (string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId = 'sub-f3-' . Str::random(6);

    $guardAt = match ($scenario) {
        'fresh_guard' => now()->subMinute()->startOfSecond(),
        'expired_guard_with_owner' => now()->subMinutes(10)->startOfSecond(),
        default => null,
    };
    $guardOwner = in_array($scenario, ['fresh_guard', 'expired_guard_with_owner'], true)
        ? 'owner-' . Str::random(16)
        : null;

    $sub = Subscription::create([
        'subscription_id' => $subId,
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => null,
        'status' => 'active',
        'management_state' => 'active',
        'operation_guard_owner' => $guardOwner,
        'operation_guard_at' => $guardAt,
        'idempotency_key' => Str::uuid()->toString(),
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-F3-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_failed_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $transferredOwner = 'mgmt-transfer-during-get';
    $transferredAt = now()->startOfSecond();

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => function () use ($sub, $subId, $scenario, $transferredOwner, $transferredAt) {
            if ($scenario === 'ownership_acquired_during_get') {
                Subscription::whereKey($sub->id)->update([
                    'operation_guard_owner' => $transferredOwner,
                    'operation_guard_at' => $transferredAt,
                    'status' => 'inactive',
                    'management_state' => 'cancelled',
                    'metadata' => [
                        'token_status' => 'missing_saved_token',
                        'cancellation' => [
                            'provider_confirmed' => true,
                            'provider_status' => 'inactive',
                            'confirmed_at' => '2026-11-26T01:00:00+00:00',
                        ],
                    ],
                ]);
            }

            return Http::response([
                'id' => $subId,
                'amount' => '100000',
                'currency' => 'IDR',
                'status' => 'active',
                'saved_token_id' => 'saved-tok-f3-recov',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
            ], 200);
        },
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation);

    if ($scenario === 'cleared_guard') {
        expect($res['success'])->toBeTrue();
        expect($sub->fresh()->token)->toBe('saved-tok-f3-recov');
        return;
    }

    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    $fresh = $sub->fresh();

    if ($scenario === 'ownership_acquired_during_get') {
        expect($fresh->status)->toBe('inactive');
        expect($fresh->management_state)->toBe('cancelled');
        expect($fresh->operation_guard_owner)->toBe($transferredOwner);
        expect($fresh->operation_guard_at?->toIso8601String())->toBe($transferredAt->toIso8601String());
        expect($fresh->metadata['cancellation']['confirmed_at'])->toBe('2026-11-26T01:00:00+00:00');
    } else {
        Http::assertNothingSent();
        expect($fresh->operation_guard_owner)->toBe($guardOwner);
        expect($fresh->operation_guard_at?->toIso8601String())->toBe($guardAt->toIso8601String());
        expect($fresh->management_state)->toBe('active');
    }
})->with([
    'fresh guard blocks' => ['fresh_guard'],
    'expired guard with owner still blocks' => ['expired_guard_with_owner'],
    'cleared guard allows unmanaged recovery' => ['cleared_guard'],
    'ownership acquired during GET aborts write' => ['ownership_acquired_during_get'],
]);

test('E5.1: F4 preserves creation evidence and blocks duplicate POST without overwriting foreign IDs or compensating on provider', function (string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $otherUser = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $createdSubId = 'sub-f4-' . Str::random(6);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-F4-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $postCalls = 0;
    $failNextCreate = false;

    Subscription::creating(function () use (&$failNextCreate) {
        if ($failNextCreate) {
            throw new \RuntimeException('Simulated DB constraint failure during Step 6 save');
        }
    });

    try {
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => function () use (
                $donatur, $otherUser, $campaign, $donation, $createdSubId, $scenario, &$postCalls, &$failNextCreate
            ) {
                $postCalls++;

                if ($scenario === 'protected_existing') {
                    Subscription::create([
                        'subscription_id' => $createdSubId,
                        'user_id' => $donatur->id,
                        'campaign_id' => $campaign->id,
                        'nominal' => 100000,
                        'frequency' => 'bulanan',
                        'interval' => 1,
                        'interval_unit' => 'month',
                        'payment_type' => 'credit_card',
                        'token' => 'saved-tok-f4',
                        'status' => 'inactive',
                        'management_state' => 'cancelled',
                        'idempotency_key' => 'sub-f4-prot-' . Str::random(6),
                        'metadata' => ['token_status' => 'verified_saved_token', 'cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']],
                    ]);
                } elseif ($scenario === 'identity_conflict') {
                    Subscription::create([
                        'subscription_id' => $createdSubId,
                        'user_id' => $otherUser->id,
                        'campaign_id' => $campaign->id,
                        'nominal' => 100000,
                        'frequency' => 'bulanan',
                        'interval' => 1,
                        'interval_unit' => 'month',
                        'payment_type' => 'credit_card',
                        'token' => 'saved-tok-f4-foreign',
                        'status' => 'active',
                        'idempotency_key' => 'sub-f4-foreign-' . Str::random(6),
                    ]);
                } elseif ($scenario === 'existing_donation_sub_conflict') {
                    DB::table('donations')->where('id', $donation->id)->update(['subscription_id' => 'sub-existing-prior-001']);
                } elseif ($scenario === 'local_persistence_failure') {
                    $failNextCreate = true;
                }

                return Http::response([
                    'id' => $createdSubId,
                    'amount' => '100000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            },
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $createdSubId => Http::response([
                'id' => $createdSubId,
                'amount' => '100000',
                'currency' => 'IDR',
                'status' => 'active',
                'saved_token_id' => 'saved-tok-f4',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => now()->addMonth()->toIso8601String()],
            ], 200),
        ]);

        $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-f4', '100000.00');
        $failNextCreate = false;

        expect($res['success'])->toBeFalse();
        expect($postCalls)->toBe(1);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/cancel') || str_contains($req->url(), '/disable'));

        $freshDon = $donation->fresh();
        expect($freshDon->status)->toBe('terverifikasi');

        if ($scenario === 'protected_existing') {
            expect($freshDon->payment_status_detail)->toBe('subscription_created_protected_conflict');
            expect(Subscription::where('subscription_id', $createdSubId)->first()->management_state)->toBe('cancelled');
            $retry = $service->activateSubscriptionFromInitialCharge($freshDon, 'saved-tok-f4', '100000.00');
            expect($retry['success'])->toBeFalse();
            expect($postCalls)->toBe(1);
        } elseif ($scenario === 'identity_conflict') {
            expect($freshDon->subscription_id)->toBeNull();
            expect($freshDon->payment_status_detail)->toBe('subscription_created_conflict_manual_review');
            $retry = $service->activateSubscriptionFromInitialCharge($freshDon, 'saved-tok-f4', '100000.00');
            expect($retry['success'])->toBeFalse();
            expect($postCalls)->toBe(1);
        } elseif ($scenario === 'existing_donation_sub_conflict') {
            expect($freshDon->subscription_id)->toBe('sub-existing-prior-001');
            expect($freshDon->payment_status_detail)->toBe('subscription_created_conflict_manual_review');
            $retry = $service->activateSubscriptionFromInitialCharge($freshDon, 'saved-tok-f4', '100000.00');
            expect($retry['success'])->toBeFalse();
            expect($postCalls)->toBe(1);
        } elseif ($scenario === 'local_persistence_failure') {
            expect($res['recoverable'])->toBeTrue();
            expect($freshDon->subscription_id)->toBe($createdSubId);
            expect($freshDon->payment_status_detail)->toBe('subscription_local_save_failed_recoverable');
            $retry = $service->activateSubscriptionFromInitialCharge($freshDon, 'saved-tok-f4', '100000.00');
            expect($retry['success'])->toBeTrue();
            expect($postCalls)->toBe(1);
            expect($retry['subscription']?->subscription_id)->toBe($createdSubId);
        }
    } finally {
        $failNextCreate = false;
    }
})->with([
    'provider success then protected existing subscription' => ['protected_existing'],
    'provider success then foreign identity conflict' => ['identity_conflict'],
    'provider success then existing donation subscription_id conflict' => ['existing_donation_sub_conflict'],
    'provider success then local persistence exception recoverable' => ['local_persistence_failure'],
]);

test('E5.1: 8.1 missing explicit verifiedPaymentAmount queries Status API instead of trusting local nominal and fails closed on mismatch', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-81-STATUS-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $statusGetCalls = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $donation->order_id . '/status' => function () use ($donation, &$statusGetCalls) {
            $statusGetCalls++;
            return Http::response([
                'order_id' => $donation->order_id,
                'status_code' => '200',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
                'gross_amount' => '200000.00', // Mismatch!
                'currency' => 'IDR',
                'saved_token_id' => 'saved-tok-81',
            ], 200);
        },
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-81');
    expect($res['success'])->toBeFalse();
    expect($statusGetCalls)->toBe(1);
    expect(Subscription::count())->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/v1/subscriptions'));
});

// ============================================================
// E5.1-TZ CHECKPOINT TZ0.2: ACTIVATION RECOVERY RESIDUAL CHECKS
// ============================================================

test('TZ0: MidtransSubscriptionResponseException with conflicting local Subscription marks manual review without attaching foreign ID', function () {
    $donatur = User::factory()->donatur()->create();
    $otherUser = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $providerSubId = 'sub-tz02-conf-' . Str::random(6);

    Subscription::create([
        'subscription_id' => $providerSubId,
        'user_id' => $otherUser->id,
        'campaign_id' => $campaign->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-foreign-tz02',
        'status' => 'active',
        'idempotency_key' => 'sub-key-foreign-' . Str::random(6),
    ]);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-TZ02-RESP-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $postCalls = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => function () use ($providerSubId, &$postCalls) {
            $postCalls++;
            // Returns missing amount so createSubscription throws MidtransSubscriptionResponseException with providerSubId
            return Http::response([
                'id' => $providerSubId,
                'status' => 'active',
                'currency' => 'IDR',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-tz02', '100000.00');
    expect($res['success'])->toBeFalse();
    expect($res['recoverable'])->toBeFalse();
    expect($postCalls)->toBe(1);

    $fresh = $donation->fresh();
    expect($fresh->subscription_id)->toBeNull();
    expect($fresh->payment_status_detail)->toBe('subscription_created_conflict_manual_review');
    expect($fresh->status)->toBe('terverifikasi');

    // Retry blocked early with 0 HTTP calls
    $retry = $service->activateSubscriptionFromInitialCharge($fresh, 'saved-tok-tz02', '100000.00');
    expect($retry['success'])->toBeFalse();
    expect($retry['recoverable'])->toBeFalse();
    expect($postCalls)->toBe(1);
});

test('TZ0: manual review markers block both Step 1 and Step 3 shortcuts with zero HTTP calls', function (string $marker) {
    Http::fake();

    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $sharedKey = Str::uuid()->toString();
    $subId = 'sub-tz02-mr-' . Str::random(6);

    Subscription::create([
        'subscription_id' => $subId,
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 100000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-mr',
        'status' => 'active',
        'management_state' => 'active',
        'idempotency_key' => $sharedKey,
    ]);

    // Case A: Step 1 shortcut candidate (subscription_id set) + manual-review marker
    $donWithSubId = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-TZ02-S1-' . Str::random(6),
        'subscription_id' => $subId,
        'status' => 'terverifikasi',
        'payment_status_detail' => $marker,
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $res1 = $service->activateSubscriptionFromInitialCharge($donWithSubId, 'saved-tok-mr', '100000.00');
    expect($res1['success'])->toBeFalse();
    expect($res1['recoverable'])->toBeFalse();

    // Case B: Step 3 shortcut candidate (matching idempotency_key) + manual-review marker
    $donWithIdemKey = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-TZ02-S3-' . Str::random(6),
        'subscription_id' => null,
        'status' => 'terverifikasi',
        'payment_status_detail' => $marker,
        'idempotency_key' => 'init_' . $sharedKey,
    ]);

    $res3 = $service->activateSubscriptionFromInitialCharge($donWithIdemKey, 'saved-tok-mr', '100000.00');
    expect($res3['success'])->toBeFalse();
    expect($res3['recoverable'])->toBeFalse();
    expect($donWithIdemKey->fresh()->subscription_id)->toBeNull();
    Http::assertNothingSent();
})->with([
    'manual_review' => ['subscription_created_conflict_manual_review'],
    'protected_conflict' => ['subscription_created_protected_conflict'],
]);

// ============================================================
// E5.1-TZ: TIMEZONE & SCHEDULE PREPARATION FEATURE TESTS
// ============================================================

test('TZ: subscription creation persists explicit +07:00 and +0700 provider schedules as canonical UTC and serializes ISO 8601 Z', function (string $providerTs, string $expectedDbUtc, string $expectedIsoUtc) {
    Carbon::setTestNow(Carbon::parse('2026-11-26 02:00:00', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $subId = 'sub-tz-create-' . Str::random(6);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '200',
                'transaction_status' => 'capture',
                'fraud_status' => 'accept',
                'saved_token_id' => 'saved-tok-tz-1',
                'gross_amount' => '100000.00',
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
                'id' => $subId,
                'amount' => '100000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => [
                    'interval' => 1,
                    'interval_unit' => 'month',
                    'next_execution_at' => $providerTs,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($donatur)->postJson(
            route('donatur.donasi.uang', $campaign->slug),
            [
                'nominal' => 100000,
                'is_recurring' => '1',
                'frequency' => 'bulanan',
                'payment_type' => 'credit_card',
                'payment_token' => 'temp-tok-tz-1',
                'recurring_consent' => 'accepted',
            ]
        );

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'subscription_id' => $subId,
            'next_execution_at' => $expectedIsoUtc,
            'schedule' => [
                'status' => 'confirmed',
                'next_execution_at_utc' => $expectedIsoUtc,
                'display_timezone' => 'Asia/Jakarta',
                'is_upcoming_billing_guaranteed' => false,
                'freshness' => 'fresh',
                'is_displayable_upcoming' => true,
            ],
        ]);

        // Verify Eloquent & raw DB persistence round-trip in UTC
        $sub = Subscription::where('subscription_id', $subId)->first();
        expect($sub->next_execution_at?->timezoneName)->toBe('UTC');
        expect($sub->next_execution_at?->toIso8601ZuluString())->toBe($expectedIsoUtc);
        expect($sub->metadata['schedule']['next_execution_source'])->toBe('provider_confirmed');
        expect($sub->metadata['schedule']['next_execution_utc'])->toBe($expectedIsoUtc);

        $rawCol = DB::table('subscriptions')->where('id', $sub->id)->value('next_execution_at');
        expect($rawCol)->toBe($expectedDbUtc);

        $reloaded = Subscription::find($sub->id);
        expect($reloaded->next_execution_at?->toIso8601ZuluString())->toBe($expectedIsoUtc);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    '+07:00 canonical example' => ['2026-12-24T13:23:36+07:00', '2026-12-24 06:23:36', '2026-12-24T06:23:36Z'],
    '+0700 space format' => ['2026-12-24 13:23:36 +0700', '2026-12-24 06:23:36', '2026-12-24T06:23:36Z'],
    'Z UTC midnight crossing' => ['2026-12-23T17:30:00Z', '2026-12-23 17:30:00', '2026-12-23T17:30:00Z'],
    'year crossing +07:00' => ['2027-01-01T02:00:00+07:00', '2026-12-31 19:00:00', '2026-12-31T19:00:00Z'],
]);

test('TZ: missing, offset-free, or invalid provider schedule during creation stores null without fabricating confirmed next_execution_at', function (mixed $badScheduleTs, string $expectedSource) {
    Carbon::setTestNow(Carbon::parse('2026-11-26 02:00:00', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $service = app(\App\Services\MidtransPaymentService::class);
        $subId = 'sub-tz-unconf-' . Str::random(6);

        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 100000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-TZ-UNCONF-' . Str::random(6),
            'status' => 'terverifikasi',
            'payment_status_detail' => 'settled',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $schedulePayload = ['interval' => 1, 'interval_unit' => 'month'];
        if ($badScheduleTs !== '__OMIT__') {
            $schedulePayload['next_execution_at'] = $badScheduleTs;
        }

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
                'id' => $subId,
                'amount' => '100000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => $schedulePayload,
            ], 200),
        ]);

        $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-unconf', '100000.00');
        expect($res['success'])->toBeTrue();

        $sub = $res['subscription']->fresh();
        expect($sub->next_execution_at)->toBeNull();
        expect($sub->metadata['schedule']['next_execution_source'])->toBe($expectedSource);
        expect($sub->metadata['schedule']['next_execution_utc'])->toBeNull();
        expect($sub->metadata['schedule']['estimated_start_time_utc'])->toBe('2026-12-26T02:00:00Z');
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'missing schedule ts' => ['__OMIT__', 'unavailable'],
    'null schedule ts' => [null, 'unavailable'],
    'date-only schedule ts' => ['2026-12-26', 'invalid'],
    'T-separated offset-free schedule ts' => ['2026-12-26T09:00:00', 'ambiguous_offset_free'],
    'invalid calendar feb 29 non-leap' => ['2026-02-29T09:00:00+07:00', 'invalid'],
    'malformed schedule ts' => ['bad-date', 'invalid'],
]);

test('TZ: outbound start_time uses deterministic UTC +0000 and handles weekly, monthly, and month-end leap/non-leap dates', function (string $frozenUtc, string $frequency, string $expectedStartTime) {
    Carbon::setTestNow(Carbon::parse($frozenUtc, 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $service = app(\App\Services\MidtransPaymentService::class);
        $capturedStartTime = null;

        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 100000,
            'is_recurring' => false,
            'frequency' => $frequency,
            'order_id' => 'INIT-TZ-OUT-' . Str::random(6),
            'status' => 'terverifikasi',
            'payment_status_detail' => 'settled',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => function (\Illuminate\Http\Client\Request $req) use (&$capturedStartTime) {
                $capturedStartTime = $req['schedule']['start_time'] ?? null;
                return Http::response([
                    'id' => 'sub-tz-out-' . Str::random(5),
                    'amount' => '100000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => [
                        'interval' => 1,
                        'interval_unit' => $req['schedule']['interval_unit'],
                        'next_execution_at' => '2026-12-24T06:00:00Z',
                    ],
                ], 200);
            },
        ]);

        $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-out', '100000.00');
        expect($res['success'])->toBeTrue();
        expect($capturedStartTime)->toBe($expectedStartTime);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'weekly' => ['2026-11-26 02:15:00', 'mingguan', '2026-12-03 02:15:00 +0000'],
    'monthly standard' => ['2026-11-26 02:15:00', 'bulanan', '2026-12-26 02:15:00 +0000'],
    'jan 31 non-leap clamps to feb 28' => ['2026-01-31 18:00:00', 'bulanan', '2026-02-28 18:00:00 +0000'],
    'jan 31 leap clamps to feb 29' => ['2028-01-31 18:00:00', 'bulanan', '2028-02-29 18:00:00 +0000'],
]);

test('TZ: existing subscription recovery converts explicit +07:00 schedule to UTC and rejects ambiguous/missing without fabricating', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);
    $subId1 = 'sub-tz-recov-ok-' . Str::random(5);
    $subId2 = 'sub-tz-recov-amb-' . Str::random(5);

    $d1 = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-TZ-REC1-' . Str::random(5),
        'subscription_id' => $subId1,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_failed_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $d2 = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-TZ-REC2-' . Str::random(5),
        'subscription_id' => $subId2,
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_failed_recoverable',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId1 => Http::response([
            'id' => $subId1,
            'amount' => '100000',
            'currency' => 'IDR',
            'status' => 'active',
            'saved_token_id' => 'tok-rec-ok',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24T13:23:36+07:00'],
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId2 => Http::response([
            'id' => $subId2,
            'amount' => '100000',
            'currency' => 'IDR',
            'status' => 'active',
            'saved_token_id' => 'tok-rec-amb',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24 13:23:36'],
        ], 200),
    ]);

    $r1 = $service->activateSubscriptionFromInitialCharge($d1);
    expect($r1['success'])->toBeTrue();
    expect($r1['subscription']->fresh()->next_execution_at?->toIso8601ZuluString())->toBe('2026-12-24T06:23:36Z');
    expect($r1['subscription']->fresh()->metadata['schedule']['next_execution_source'])->toBe('provider_confirmed');
    expect($r1['subscription']->fresh()->metadata['schedule']['timezone_resolution'])->toBe('explicit_offset');
    expect($r1['subscription']->fresh()->metadata['schedule']['assumed_offset'])->toBeNull();
    expect($r1['subscription']->fresh()->metadata['schedule']['source_context'])->toBe('midtrans_subscription_get');

    // Recovery GET returns documented GMT+7 offset-free schedule -> normalized to canonical UTC with provenance
    $r2 = $service->activateSubscriptionFromInitialCharge($d2);
    expect($r2['success'])->toBeTrue();
    $freshR2 = $r2['subscription']->fresh();
    expect($freshR2->next_execution_at?->toIso8601ZuluString())->toBe('2026-12-24T06:23:36Z');
    expect($freshR2->metadata['schedule']['next_execution_source'])->toBe('provider_confirmed');
    expect($freshR2->metadata['schedule']['next_execution_utc'])->toBe('2026-12-24T06:23:36Z');
    expect($freshR2->metadata['schedule']['timezone_resolution'])->toBe('midtrans_subscription_gmt7_contract');
    expect($freshR2->metadata['schedule']['assumed_offset'])->toBe('+07:00');
    expect($freshR2->metadata['schedule']['source_context'])->toBe('midtrans_subscription_get');
});

test('E5.5-C.1: trusted offset-free Midtrans Create response normalizes sandbox GMT+7 contract to UTC with WIB display and financial invariants', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    Http::preventStrayRequests();
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $subId = 'sub-c-sandbox-contract-' . Str::random(6);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '200',
                'transaction_status' => 'capture',
                'fraud_status' => 'accept',
                'saved_token_id' => 'saved-tok-subc-1',
                'gross_amount' => '15000.00',
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
                'id' => $subId,
                'amount' => '15000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => [
                    'interval' => 1,
                    'interval_unit' => 'week',
                    'next_execution_at' => '2026-12-03 14:27:12',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($donatur)->postJson(
            route('donatur.donasi.uang', $campaign->slug),
            [
                'nominal' => 15000,
                'is_recurring' => '1',
                'frequency' => 'mingguan',
                'payment_type' => 'credit_card',
                'payment_token' => 'temp-tok-subc-1',
                'recurring_consent' => 'accepted',
            ]
        );

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'subscription_id' => $subId,
            'next_execution_at' => '2026-12-03T07:27:12Z',
            'schedule' => [
                'status' => 'confirmed',
                'next_execution_at_utc' => '2026-12-03T07:27:12Z',
                'display_timezone' => 'Asia/Jakarta',
                'is_upcoming_billing_guaranteed' => false,
                'freshness' => 'fresh',
                'is_displayable_upcoming' => true,
                'display_wib' => '3 Desember 2026, 14:27:12 WIB',
            ],
        ]);

        $sub = Subscription::where('subscription_id', $subId)->firstOrFail();
        expect($sub->next_execution_at?->toIso8601ZuluString())->toBe('2026-12-03T07:27:12Z');
        expect($sub->metadata['schedule'])->toMatchArray([
            'next_execution_source' => 'provider_confirmed',
            'next_execution_utc' => '2026-12-03T07:27:12Z',
            'freshness' => 'fresh',
            'last_refresh_status' => 'provider_confirmed',
            'synced_at' => '2026-11-26T07:27:12Z',
            'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
            'assumed_offset' => '+07:00',
            'source_context' => 'midtrans_subscription_create',
        ]);
        expect((int) $sub->nominal)->toBe(15000);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(15000);
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.5-C.1: contradictory Create response interval or status fails closed on schedule without downgrading donation or retrying', function (array $badFields) {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    Http::preventStrayRequests();
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $service = app(\App\Services\MidtransPaymentService::class);
        $subId = 'sub-create-contra-' . Str::random(6);

        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 50000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-CONTRA-' . Str::random(6),
            'status' => 'terverifikasi',
            'payment_status_detail' => 'settled',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $responsePayload = array_replace_recursive([
            'id' => $subId,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => [
                'interval' => 1,
                'interval_unit' => 'month',
                'next_execution_at' => '2026-12-26 14:27:12',
            ],
        ], $badFields);

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response($responsePayload, 200),
        ]);

        $res = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-contra', '50000.00');
        expect($res['success'])->toBeTrue();

        $sub = $res['subscription']->fresh();
        expect($sub->subscription_id)->toBe($subId);
        expect($sub->next_execution_at)->toBeNull();
        expect($sub->metadata['schedule']['next_execution_source'])->toBe('invalid');
        expect($sub->metadata['schedule']['reason'])->toBe('unverified_create_schedule_context');
        expect($sub->metadata['schedule']['next_execution_utc'])->toBeNull();

        $freshDonation = $donation->fresh();
        expect($freshDonation->status)->toBe('terverifikasi');
        expect($freshDonation->subscription_id)->toBe($subId);
        expect($freshDonation->payment_status_detail)->toBe('subscription_activated');
        expect(count(Http::recorded()))->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'contradictory interval_unit week for monthly' => [['schedule' => ['interval_unit' => 'week']]],
    'contradictory interval 2' => [['schedule' => ['interval' => 2]]],
    'untrusted inactive status on create' => [['status' => 'inactive']],
    'untrusted unknown status on create' => [['status' => 'unexpected_state']],
]);

test('E5.5-C.1: persistence boundary policy rejects unsafe lower boundary without SQL crash and persists safe boundaries cleanly', function () {
    Http::preventStrayRequests();
    $donatur = User::factory()->donatur()->create();
    $campaign = createSubscriptionCampaign();
    $service = app(\App\Services\MidtransPaymentService::class);

    // 1. Unsafe lower boundary (1970-01-01 07:00:01 GMT+7 -> 1970-01-01T00:00:01Z) fails closed safely
    $dUnsafe = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-UNSAFE-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    $subUnsafeId = 'sub-unsafe-' . Str::random(5);

    // 2. Safe lower floor (1970-01-02 07:00:00 GMT+7 -> 1970-01-02T00:00:00Z) & upper bound (2038-01-19 10:14:07 GMT+7)
    $dSafeLow = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-SAFELOW-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    $subSafeLowId = 'sub-safelow-' . Str::random(5);

    $dSafeHigh = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 50000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-SAFEHIGH-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    $subSafeHighId = 'sub-safehigh-' . Str::random(5);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::sequence()
            ->push(['id' => $subUnsafeId, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '1970-01-01 07:00:01']], 200)
            ->push(['id' => $subSafeLowId, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '1970-01-02 07:00:00']], 200)
            ->push(['id' => $subSafeHighId, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2038-01-19 10:14:07']], 200),
    ]);

    $rUnsafe = $service->activateSubscriptionFromInitialCharge($dUnsafe, 'tok-b1', '50000.00');
    expect($rUnsafe['success'])->toBeTrue();
    expect($rUnsafe['subscription']->fresh()->next_execution_at)->toBeNull();
    expect($rUnsafe['subscription']->fresh()->metadata['schedule']['reason'])->toBe('unsafe_mysql_timestamp_lower_boundary');
    expect($dUnsafe->fresh()->status)->toBe('terverifikasi');

    $rLow = $service->activateSubscriptionFromInitialCharge($dSafeLow, 'tok-b2', '50000.00');
    expect($rLow['success'])->toBeTrue();
    expect($rLow['subscription']->fresh()->next_execution_at?->toIso8601ZuluString())->toBe('1970-01-02T00:00:00Z');

    $rHigh = $service->activateSubscriptionFromInitialCharge($dSafeHigh, 'tok-b3', '50000.00');
    expect($rHigh['success'])->toBeTrue();
    expect($rHigh['subscription']->fresh()->next_execution_at?->toIso8601ZuluString())->toBe('2038-01-19T03:14:07Z');
});

test('E5.5-C.1: known-ID recovery rejects identity, amount, currency, interval, status mismatches and invalid offset-free formats while preserving stale GMT+7 schedules', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    Http::preventStrayRequests();
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createSubscriptionCampaign();
        $service = app(\App\Services\MidtransPaymentService::class);
        $subIdMismatch = 'sub-rec-mismatch-' . Str::random(5);
        $subIdBadFormat = 'sub-rec-badfmt-' . Str::random(5);
        $subIdStale = 'sub-rec-stale-' . Str::random(5);

        $dMismatch = Donation::create([
            'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang', 'nominal' => 50000,
            'is_recurring' => false, 'frequency' => 'bulanan', 'order_id' => 'INIT-REC-MIS-' . Str::random(5),
            'subscription_id' => $subIdMismatch, 'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activation_failed_recoverable', 'idempotency_key' => Str::uuid()->toString(),
        ]);

        $dBadFormat = Donation::create([
            'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang', 'nominal' => 50000,
            'is_recurring' => false, 'frequency' => 'bulanan', 'order_id' => 'INIT-REC-BAD-' . Str::random(5),
            'subscription_id' => $subIdBadFormat, 'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activation_failed_recoverable', 'idempotency_key' => Str::uuid()->toString(),
        ]);

        $existingSub = Subscription::create([
            'subscription_id' => $subIdStale, 'user_id' => $donatur->id, 'campaign_id' => $campaign->id,
            'nominal' => 50000, 'frequency' => 'bulanan', 'interval' => 1, 'interval_unit' => 'month',
            'payment_type' => 'credit_card', 'token' => 'tok-stale-rec', 'status' => 'active',
            'management_state' => null, 'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                    'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
                    'assumed_offset' => '+07:00',
                    'source_context' => 'midtrans_subscription_create',
                ],
            ],
        ]);
        $dStale = Donation::create([
            'campaign_id' => $campaign->id, 'user_id' => $donatur->id, 'jenis' => 'uang', 'nominal' => 50000,
            'is_recurring' => false, 'frequency' => 'bulanan', 'order_id' => 'INIT-REC-STL-' . Str::random(5),
            'subscription_id' => $subIdStale, 'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activation_failed_recoverable', 'idempotency_key' => Str::uuid()->toString(),
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subIdMismatch => Http::response([
                'id' => $subIdMismatch, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active',
                'saved_token_id' => 'tok-mis',
                'schedule' => ['interval' => 1, 'interval_unit' => 'week', 'next_execution_at' => '2026-12-03 14:27:12'],
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subIdBadFormat => Http::response([
                'id' => $subIdBadFormat, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active',
                'saved_token_id' => 'tok-bad',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24'],
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subIdStale => Http::response([
                'id' => $subIdStale, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active',
                'saved_token_id' => 'tok-stale-rec',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24T13:23:36'],
            ], 200),
        ]);

        $rMis = $service->activateSubscriptionFromInitialCharge($dMismatch);
        expect($rMis['success'])->toBeFalse();
        expect(Subscription::where('subscription_id', $subIdMismatch)->exists())->toBeFalse();

        $rBad = $service->activateSubscriptionFromInitialCharge($dBadFormat);
        expect($rBad['success'])->toBeTrue();
        expect($rBad['subscription']->fresh()->next_execution_at)->toBeNull();
        expect($rBad['subscription']->fresh()->metadata['schedule']['reason'])->toBe('date_only_timestamp');

        $rStale = $service->activateSubscriptionFromInitialCharge($dStale);
        expect($rStale['success'])->toBeTrue();
        $staleMeta = $existingSub->fresh()->metadata['schedule'];
        expect($staleMeta['next_execution_utc'])->toBe('2026-12-24T06:23:36Z');
        expect($staleMeta['freshness'])->toBe('stale_preserved');
        expect($staleMeta['timezone_resolution'])->toBe('midtrans_subscription_gmt7_contract');
        expect($staleMeta['assumed_offset'])->toBe('+07:00');
        expect($staleMeta['source_context'])->toBe('midtrans_subscription_create');
        expect($staleMeta['last_refresh_status'])->toBe('ambiguous_offset_free');
        expect($staleMeta['last_refresh_reason'])->toBe('unsupported_offset_free_format');
    } finally {
        Carbon::setTestNow();
    }
});

