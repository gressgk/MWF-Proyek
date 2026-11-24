<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
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

    Config::set('services.midtrans.server_key', 'SB-Mid-server-test-key-12345');
    Config::set('services.midtrans.client_key', 'SB-Mid-client-test-key-12345');
    Config::set('services.midtrans.signature_secret_key', 'SB-Mid-signing-secret-test-key-67890');
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
    expect($errorMsg)->not->toContain('SB-Mid-server');
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

    // Initial charge berhasil, tapi Create Subscription API panggilan pertama error (500), lalu sukses saat dipulihkan (sequence)
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code'        => '200',
            'transaction_status' => 'capture',
            'fraud_status'       => 'accept',
            'saved_token_id'     => 'saved-tok-recov-001',
            'gross_amount'       => '100000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::sequence()
            ->push(['error_messages' => ['Midtrans subscription server error']], 500)
            ->push(['id' => 'sub-recovered-001', 'status' => 'active'], 200),
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

    // Pemulihan (recovery): saat gateway langganan dipanggil kembali, panggilan kedua sukses
    $service = app(\App\Services\MidtransPaymentService::class);
    $recovery = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-recov-001');

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
    $donation = Donation::where('user_id', $donatur->id)->first();
    expect($donation)->not->toBeNull();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('subscription_activation_failed_recoverable');
    expect(Subscription::count())->toBe(0);
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
    $result = $service->activateSubscriptionFromInitialCharge($donation, 'saved-token-concurrent');

    expect($result['success'])->toBeFalse();
    expect($result['recoverable'])->toBeTrue();
    expect(Subscription::count())->toBe(0);
    Http::assertNothingSent();
});

test('security: stale activation lease can be acquired for deterministic reconciliation retry', function () {
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

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id'     => 'sub-stale-retry-001',
            'status' => 'active',
        ], 200),
    ]);

    $result = app(\App\Services\MidtransPaymentService::class)
        ->activateSubscriptionFromInitialCharge($donation, 'saved-token-stale');

    expect($result['success'])->toBeTrue();
    Http::assertSentCount(1);
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
