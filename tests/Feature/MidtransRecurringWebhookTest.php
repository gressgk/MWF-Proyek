<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CampaignProgressService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
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

    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY_12345');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY_12345');
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
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
    $secret = $signingSecret ?? config('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_67890');
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

    // Default mock untuk verifikasi Get Subscription linkage dan Transaction Status API otoritatif (TZ0.1)
    $finalTx = array_merge($txFields, $overrides['transaction'] ?? []);
    $effectiveOrderId = $finalTx['order_id'] ?? $finalTx['transaction_id'] ?? $orderId;
    $effectiveStatusPayload = [
        'transaction_id'     => $finalTx['transaction_id'] ?? $txFields['transaction_id'],
        'order_id'           => $effectiveOrderId,
        'status_code'        => $finalTx['status_code'] ?? $statusCode,
        'transaction_status' => $finalTx['transaction_status'] ?? $txStatus,
        'fraud_status'       => $finalTx['fraud_status'] ?? $fraudStatus,
        'gross_amount'       => $finalTx['gross_amount'] ?? $grossAmount,
        'currency'           => 'IDR',
    ];

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subId => Http::response([
            'id'              => $subId,
            'name'            => $subName,
            'status'          => 'active',
            'transaction_ids' => $txIds,
        ], 200),
        'https://api.sandbox.midtrans.com/v2/' . $effectiveOrderId . '/status' => Http::response($effectiveStatusPayload, 200),
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
// 6. SECURITY: LEGACY SUBSCRIPTION.INACTIVE TANPA SIGNATURE (VERIFIED ACK-ONLY)
// ============================================================

test('security: stale subscription.inactive without signature is verified against active provider and acknowledged ACK-only without mutating active state', function () {
    Carbon::setTestNow('2026-11-25 16:00:00');
    try {
        ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => (string) (int) $sub->nominal,
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => [
                        'interval' => (int) $sub->interval,
                        'interval_unit' => $sub->interval_unit ?: 'month',
                    ],
                ], 200);
            },
        ]);

        // Synthetic legacy fixture (bukan actual captured Sandbox notification)
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

        Carbon::setTestNow('2026-11-25 16:00:10');
        // Kontrak E5.1.8B Verified ACK-only: verifikasi penuh ke provider tetap dijalankan dan stale notification di-ACK 200 tanpa mutasi state lokal.
        $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
        $response->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('security: valid subscription.inactive verified by provider getSubscription acknowledges 200 ACK-only without mutating local status', function () {
    Carbon::setTestNow('2026-11-25 16:00:00');
    try {
        ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => (string) (int) $sub->nominal,
                    'currency' => 'IDR',
                    'status' => 'inactive',
                    'schedule' => [
                        'interval' => (int) $sub->interval,
                        'interval_unit' => $sub->interval_unit ?: 'month',
                    ],
                ], 200);
            },
        ]);

        // Synthetic legacy fixture (bukan actual captured Sandbox notification)
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

        Carbon::setTestNow('2026-11-25 16:00:10');
        $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
        $response->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
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

    // Payload resmi tanpa field fraud_status (transaction_id diselaraskan dengan mock Status API)
    $payload = officialRecurringPayload([
        'subscription_id'    => $sub->subscription_id,
        'order_id'           => $cycleOrderId,
        'transaction_status' => 'capture',
        'fraud_status'       => null, // notification tidak memuat fraud_status
        'transaction'        => ['transaction_id' => 'tx-cap-recon-001'],
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
        'transaction'        => ['transaction_id' => 'tx-cap-chal-001'],
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

test('security: signed legacy subscription.inactive notification with settlement evidence verifies provider and fails closed with 422 without mutating active state or creating donation', function () {
    Carbon::setTestNow('2026-11-25 16:00:00');
    try {
        ['subscription' => $sub] = createActiveSubscription(['status' => 'active']);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => (string) (int) $sub->nominal,
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => [
                        'interval' => (int) $sub->interval,
                        'interval_unit' => $sub->interval_unit ?: 'month',
                    ],
                ], 200);
            },
        ]);

        $orderId = 'SUB-INACT-SIGNED-001';
        $sig = makeRecurringSignature($orderId, '200', '100000.00');

        // Synthetic legacy fixture dengan VALID signature_key dan settlement evidence pada event subscription.inactive
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

        Carbon::setTestNow('2026-11-25 16:00:10');
        $response = $this->postJson(route('midtrans.webhook.subscription'), $payload);
        $response->assertStatus(422)->assertJson(['message' => 'Unsupported legacy payment evidence']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
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

// ============================================================
// 14. E5.1.7C.3: SUBSCRIPTION.UPDATE_STATUS (VERIFIED ACK-ONLY)
// ============================================================

function sandboxStatusUpdatePayload(string $subscriptionId, string $status = 'inactive', array $overrides = []): array
{
    $subscription = array_merge([
        'id' => $subscriptionId,
        'name' => 'SUB-1-1-4bd3dea2',
        'amount' => '10000',
        'currency' => 'IDR',
        'status' => $status,
        'payment_type' => 'credit_card',
        'schedule' => [
            'interval' => 1,
            'interval_unit' => 'week',
            'current_interval' => 0,
            'start_time' => '2026-12-02T15:24:35.000000Z',
            'next_execution_at' => '2026-12-02T15:24:35.000000Z',
        ],
        'retry_schedule' => [
            'interval' => 1,
            'interval_unit' => 'hour',
            'max_interval' => 3,
        ],
    ], $overrides['subscription'] ?? []);

    unset($overrides['subscription']);

    return array_merge([
        'event_name' => 'subscription.update_status',
        'subscription' => $subscription,
    ], $overrides);
}

function fakeVerifiedProviderSubscription(Subscription $sub, string $providerStatus, array $overrides = []): void
{
    $schedule = array_merge([
        'interval' => (int) $sub->interval,
        'current_interval' => 0,
        'interval_unit' => $sub->interval_unit ?: ($sub->frequency === 'mingguan' ? 'week' : 'month'),
        'start_time' => '2026-12-02 22:24:35',
        'next_execution_at' => '2026-12-02 22:24:35',
    ], $overrides['schedule'] ?? []);

    unset($overrides['schedule']);

    $body = array_merge([
        'id' => $sub->subscription_id,
        'name' => 'SUB-1-1-4bd3dea2',
        'amount' => (string) (int) $sub->nominal,
        'currency' => 'IDR',
        'created_at' => '2026-11-25 22:24:36',
        'status' => $providerStatus,
        'payment_type' => 'credit_card',
        'schedule' => $schedule,
        'retry_schedule' => [
            'interval' => 1,
            'interval_unit' => 'hour',
            'max_interval' => 3,
        ],
        'transaction_ids' => [],
    ], $overrides);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response($body, 200),
    ]);
}

function snapshotSubscriptionState(Subscription $sub): array
{
    $fresh = $sub->fresh();

    return [
        'status' => $fresh->status,
        'management_state' => $fresh->management_state,
        'nominal' => (string) $fresh->nominal,
        'frequency' => $fresh->frequency,
        'interval' => (int) $fresh->interval,
        'interval_unit' => $fresh->interval_unit,
        'payment_type' => $fresh->payment_type,
        'token' => $fresh->token,
        'next_execution_at' => $fresh->next_execution_at?->toIso8601String(),
        'operation_guard_at' => $fresh->operation_guard_at?->toIso8601String(),
        'operation_guard_owner' => $fresh->operation_guard_owner,
        'metadata' => $fresh->metadata,
        'updated_at' => $fresh->updated_at?->toIso8601String(),
    ];
}

test('e5.1.7c.3 h1: actual pause subscription.update_status inactive is acknowledged without mutating local state or progress', function () {
    Carbon::setTestNow('2026-11-25 15:37:46');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
            'next_execution_at' => '2026-12-02 22:24:35',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 15:37:47');
        fakeVerifiedProviderSubscription($sub, 'inactive');

        $progressMock = \Mockery::mock(CampaignProgressService::class);
        $progressMock->shouldNotReceive('recalculate');
        $this->app->instance(CampaignProgressService::class, $progressMock);

        $response = $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        );

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Subscription::count())->toBe(1);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h2: actual resume subscription.update_status active is acknowledged without mutating local state', function () {
    Carbon::setTestNow('2026-11-25 16:00:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-02 22:24:35',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:00:05');
        fakeVerifiedProviderSubscription($sub, 'active');

        $response = $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'active')
        );

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h3: duplicate subscription.update_status notification returns 200 with zero mutations', function () {
    Carbon::setTestNow('2026-11-25 15:37:46');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);
        $before = snapshotSubscriptionState($sub);
        $payload = sandboxStatusUpdatePayload($sub->subscription_id, 'inactive');

        Carbon::setTestNow('2026-11-25 15:39:58');
        fakeVerifiedProviderSubscription($sub, 'inactive');

        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)
            ->assertJson(['message' => 'OK']);
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)
            ->assertJson(['message' => 'OK']);

        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Subscription::count())->toBe(1);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h4: late inactive status notification when provider is active acknowledges without overwriting active local state', function () {
    Carbon::setTestNow('2026-11-25 16:05:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:05:10');
        fakeVerifiedProviderSubscription($sub, 'active');

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h5: late active status notification when provider is inactive acknowledges without reactivating paused subscription', function () {
    Carbon::setTestNow('2026-11-25 16:05:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:05:10');
        fakeVerifiedProviderSubscription($sub, 'inactive');

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'active')
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h6: cancelled subscription preserves management_state, confirmed_at, and cancellation evidence across status webhooks', function () {
    Carbon::setTestNow('2026-11-25 16:10:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'metadata' => [
                'token_status' => 'missing_saved_token',
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-24T10:15:00+00:00',
                ],
            ],
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:10:30');
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
                ->push([
                    'id' => $sub->subscription_id,
                    'amount' => '10000',
                    'currency' => 'IDR',
                    'status' => 'inactive',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
                ], 200)
                ->push([
                    'id' => $sub->subscription_id,
                    'amount' => '10000',
                    'currency' => 'IDR',
                    'status' => 'cancelled',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
                ], 200),
        ]);

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        )->assertStatus(200);

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'active')
        )->assertStatus(200);

        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h7: reconciliation subscription preserves pending state and reconciliation metadata', function () {
    Carbon::setTestNow('2026-11-25 16:15:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'reconciliation',
            'metadata' => [
                'token_status' => 'missing_saved_token',
                'last_management_operation' => 'cancel',
                'management_reconciliation' => 'provider_status_pending',
            ],
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:15:30');
        fakeVerifiedProviderSubscription($sub, 'inactive');

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        )->assertStatus(200);

        expect(snapshotSubscriptionState($sub))->toBe($before);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h8: inactive provider status notification never fabricates cancellation evidence or cancels subscription', function () {
    Carbon::setTestNow('2026-11-25 16:20:00');
    try {
        foreach ([
            ['status' => 'inactive', 'management_state' => 'paused'],
            ['status' => 'active', 'management_state' => null],
        ] as $initialState) {
            ['subscription' => $sub] = createActiveSubscription(array_merge([
                'nominal' => 10000,
                'frequency' => 'mingguan',
                'interval' => 1,
                'interval_unit' => 'week',
                'metadata' => ['token_status' => 'missing_saved_token'],
            ], $initialState));
            $before = snapshotSubscriptionState($sub);

            Carbon::setTestNow('2026-11-25 16:20:30');
            fakeVerifiedProviderSubscription($sub, 'inactive');

            $this->postJson(
                route('midtrans.webhook.subscription'),
                sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
            )->assertStatus(200);

            $fresh = $sub->fresh();
            expect(isset($fresh->metadata['cancellation']))->toBeFalse();
            expect($fresh->management_state)->not->toBe('cancelled');
            expect(snapshotSubscriptionState($sub))->toBe($before);
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        }
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h9: invalid or mismatched provider data fails closed with 422 and zero mutations', function () {
    Carbon::setTestNow('2026-11-25 16:25:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);
        $before = snapshotSubscriptionState($sub);

        $invalidResponses = [
            ['id' => 'different-sub-id', 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '20000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000.50', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'USD', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 2, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 0, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => -1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1.5, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => '1.5', 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => '1abc', 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'unknown', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'expired', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => '', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'status' => 'inactive'],
            ['id' => $sub->subscription_id, 'amount' => '10000', 'currency' => 'IDR', 'schedule' => ['interval' => 1, 'interval_unit' => 'week']],
        ];

        Carbon::setTestNow('2026-11-25 16:25:30');
        foreach ($invalidResponses as $providerPayload) {
            Http::fake([
                'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response($providerPayload, 200),
            ]);

            $this->postJson(
                route('midtrans.webhook.subscription'),
                sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
            )->assertStatus(422);

            expect(snapshotSubscriptionState($sub))->toBe($before);
            expect(Donation::count())->toBe(0);
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        }
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h10: provider timeout and 5xx errors return 503 without mutating database', function () {
    Carbon::setTestNow('2026-11-25 16:30:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:30:10');
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () {
                throw new ConnectionException('Provider timeout');
            },
        ]);

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        )->assertStatus(503);
        expect(snapshotSubscriptionState($sub))->toBe($before);

        foreach ([500, 503] as $statusCode) {
            Http::fake([
                'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response(['status_message' => 'Error'], $statusCode),
            ]);

            $this->postJson(
                route('midtrans.webhook.subscription'),
                sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
            )->assertStatus(503);
            expect(snapshotSubscriptionState($sub))->toBe($before);
        }

        expect(Donation::count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.7c.3 h11: malformed subscription.update_status payloads return 400 and never enter recurring charge handler', function () {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);

    $malformedPayloads = [
        ['event_name' => 'subscription.update_status'],
        ['event_name' => 'subscription.update_status', 'subscription' => 'invalid-string'],
        ['event_name' => 'subscription.update_status', 'subscription' => ['not-an-assoc-object']],
        ['event_name' => 'subscription.update_status', 'subscription' => ['status' => 'inactive']],
        ['event_name' => 'subscription.update_status', 'subscription' => ['id' => 12345, 'status' => 'inactive']],
        ['event_name' => 'subscription.update_status', 'subscription' => ['id' => '   ', 'status' => 'inactive']],
        ['event_name' => 'subscription.update_status', 'subscription' => ['id' => $sub->subscription_id]],
        ['event_name' => 'subscription.update_status', 'subscription' => ['id' => $sub->subscription_id, 'status' => 'pending']],
        [
            'event_name' => 'subscription.update_status',
            'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'SUB-STATUS-WITH-TX-001',
                'status_code' => '200',
                'transaction_status' => 'settlement',
                'gross_amount' => '10000.00',
            ],
        ],
        [
            'event_name' => 'subscription.update_status',
            'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
            'transaction' => null,
        ],
    ];

    foreach ($malformedPayloads as $payload) {
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid payload']);
    }

    expect(Donation::count())->toBe(0);
    Http::assertNothingSent();
});

test('e5.1.7c.3 h12: unknown subscription for subscription.update_status returns 200 without creating local data', function () {
    $payload = sandboxStatusUpdatePayload('unknown-sub-e517c3-' . Str::random(8), 'inactive');

    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(200)
        ->assertJson(['message' => 'OK']);

    expect(Subscription::count())->toBe(0);
    expect(Donation::count())->toBe(0);
    Http::assertNothingSent();
});

test('e5.1.7c.3 h13: signature on subscription.update_status never bypasses provider verification', function () {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push([
                'id' => $sub->subscription_id,
                'amount' => '99999',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
            ], 200)
            ->push(['error' => 'upstream'], 500),
    ]);

    $signedPayload = sandboxStatusUpdatePayload($sub->subscription_id, 'inactive', [
        'signature_key' => makeRecurringSignature($sub->subscription_id, '200', '10000.00'),
    ]);
    $this->postJson(route('midtrans.webhook.subscription'), $signedPayload)->assertStatus(422);

    $unsignedPayload = sandboxStatusUpdatePayload($sub->subscription_id, 'inactive');
    $this->postJson(route('midtrans.webhook.subscription'), $unsignedPayload)->assertStatus(503);
});

test('e5.1.7c.3 h14: in-flight operation guard is preserved when subscription.update_status arrives', function () {
    Carbon::setTestNow('2026-11-25 16:40:00');
    try {
        $guardOwner = Str::uuid()->toString();
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'operation_guard_at' => now(),
            'operation_guard_owner' => $guardOwner,
        ]);
        $before = snapshotSubscriptionState($sub);

        Carbon::setTestNow('2026-11-25 16:40:05');
        fakeVerifiedProviderSubscription($sub, 'inactive');

        $this->postJson(
            route('midtrans.webhook.subscription'),
            sandboxStatusUpdatePayload($sub->subscription_id, 'inactive')
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        $fresh = $sub->fresh();
        expect($fresh->operation_guard_owner)->toBe($guardOwner);
        expect($fresh->operation_guard_at)->not->toBeNull();
        expect(snapshotSubscriptionState($sub))->toBe($before);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================
// 15. E5.1.8B: LEGACY SUBSCRIPTION.INACTIVE HARDENING (VERIFIED ACK-ONLY)
// ============================================================

/**
 * Synthetic legacy fixture untuk event subscription.inactive.
 * Bukan actual captured Sandbox notification (Sandbox actual menggunakan subscription.update_status).
 */
function syntheticLegacyInactivePayload(string $subscriptionId, array $overrides = []): array
{
    $subscription = array_merge([
        'id' => $subscriptionId,
        'status' => 'inactive',
    ], $overrides['subscription'] ?? []);

    unset($overrides['subscription']);

    return array_merge([
        'event_name' => 'subscription.inactive',
        'subscription' => $subscription,
    ], $overrides);
}

function verifiedProviderPayloadFor(Subscription $sub, string $providerStatus, array $overrides = []): array
{
    $schedule = array_merge([
        'interval' => (int) $sub->interval,
        'current_interval' => 0,
        'interval_unit' => $sub->interval_unit ?: ($sub->frequency === 'mingguan' ? 'week' : 'month'),
        'start_time' => '2026-12-02 22:24:35',
        'next_execution_at' => '2026-12-02 22:24:35',
    ], $overrides['schedule'] ?? []);

    unset($overrides['schedule']);

    return array_merge([
        'id' => $sub->subscription_id,
        'name' => 'SUB-LEGACY-TEST',
        'amount' => (string) (int) $sub->nominal,
        'currency' => 'IDR',
        'status' => $providerStatus,
        'payment_type' => 'credit_card',
        'schedule' => $schedule,
        'transaction_ids' => [],
    ], $overrides);
}

test('e5.1.8b h1: verified legacy subscription.inactive returns 200 ACK-only without mutating local state or progress', function () {
    Carbon::setTestNow('2026-11-25 17:00:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-02 22:24:35',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $progressMock = \Mockery::mock(CampaignProgressService::class);
        $progressMock->shouldNotReceive('recalculate');
        $this->app->instance(CampaignProgressService::class, $progressMock);

        Carbon::setTestNow('2026-11-25 17:00:15');
        $response = $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        );

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Subscription::count())->toBe(1);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h2: late legacy subscription.inactive after resume verifies active provider and returns 200 without mutating local state', function () {
    Carbon::setTestNow('2026-11-25 17:05:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'active'), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:05:15');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h3: duplicate legacy subscription.inactive calls verify provider twice and return 200 with zero mutations', function () {
    Carbon::setTestNow('2026-11-25 17:10:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $payload = syntheticLegacyInactivePayload($sub->subscription_id);

        Carbon::setTestNow('2026-11-25 17:10:20');
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)->assertJson(['message' => 'OK']);
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(2);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Subscription::count())->toBe(1);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h4: paused subscription state and updated_at remain intact on legacy subscription.inactive', function () {
    Carbon::setTestNow('2026-11-25 17:15:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:15:30');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h5: cancelled subscription preserves exact confirmed_at, metadata, token, and updated_at across legacy inactive webhooks', function () {
    Carbon::setTestNow('2026-11-25 16:39:06');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'token' => 'saved-token-e518b-h5',
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'metadata' => [
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
            ],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        $statuses = ['inactive', 'cancelled'];
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub, $statuses) {
                $status = $statuses[$getCalls] ?? 'inactive';
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, $status), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:20:00');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id, ['subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive']])
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id, ['subscription' => ['id' => $sub->subscription_id, 'status' => 'cancelled']])
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(2);
        $fresh = $sub->fresh();
        expect($fresh->metadata['cancellation']['confirmed_at'] ?? null)->toBe('2026-11-25T16:39:06+00:00');
        expect($fresh->token)->toBe('saved-token-e518b-h5');
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h6: pending reconciliation preserves business state and reconciliation metadata', function (string $pendingOp) {
    Carbon::setTestNow('2026-11-25 17:25:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => $pendingOp === 'resume' ? 'inactive' : 'active',
            'management_state' => 'reconciliation',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'last_management_operation' => $pendingOp,
                'management_reconciliation' => 'provider_status_pending',
            ],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:25:30');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
})->with(['pause', 'resume', 'cancel']);

test('e5.1.8b h7: occupied operation guard is untouched by legacy subscription.inactive webhook', function () {
    Carbon::setTestNow('2026-11-25 17:30:00');
    try {
        $guardOwner = Str::uuid()->toString();
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'operation_guard_at' => now(),
            'operation_guard_owner' => $guardOwner,
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:30:20');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        $fresh = $sub->fresh();
        expect($fresh->operation_guard_owner)->toBe($guardOwner);
        expect($fresh->operation_guard_at?->toIso8601String())->toBe($before['operation_guard_at']);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h8: provider data mismatch on legacy inactive webhook fails closed with 422', function (array $providerOverrides) {
    Carbon::setTestNow('2026-11-25 17:35:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $providerBody = verifiedProviderPayloadFor($sub, 'inactive');
        foreach ($providerOverrides as $key => $val) {
            if ($val === '__UNSET__') {
                unset($providerBody[$key]);
            } else {
                $providerBody[$key] = $val;
            }
        }

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $providerBody) {
                $getCalls++;
                return Http::response($providerBody, 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:35:20');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(422)->assertJson(['message' => 'Provider subscription verification failed']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'wrong_id' => [['id' => 'different-provider-id']],
    'amount_mismatch' => [['amount' => '20000']],
    'amount_fractional' => [['amount' => '10000.50']],
    'currency_mismatch' => [['currency' => 'USD']],
    'missing_schedule' => [['schedule' => '__UNSET__']],
    'invalid_interval_mismatch' => [['schedule' => ['interval' => 2, 'interval_unit' => 'week']]],
    'invalid_interval_zero' => [['schedule' => ['interval' => 0, 'interval_unit' => 'week']]],
    'invalid_interval_non_numeric' => [['schedule' => ['interval' => '1abc', 'interval_unit' => 'week']]],
    'invalid_interval_unit' => [['schedule' => ['interval' => 1, 'interval_unit' => 'month']]],
    'missing_status' => [['status' => '__UNSET__']],
    'empty_status' => [['status' => '']],
    'non_string_status' => [['status' => ['inactive']]],
    'unsupported_status_disabled' => [['status' => 'disabled']],
    'unsupported_status_expired' => [['status' => 'expired']],
    'unsupported_status_unknown' => [['status' => 'unknown']],
]);

test('e5.1.8b h9: provider unavailable on legacy inactive webhook returns 503 with zero mutation', function (string $scenario) {
    Carbon::setTestNow('2026-11-25 17:40:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $callbackHits = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($scenario, &$callbackHits) {
                $callbackHits++;
                if ($scenario === 'connection_exception') {
                    throw new ConnectionException('Provider timeout');
                }
                $status = match ($scenario) {
                    'http_404' => 404,
                    'http_500' => 500,
                    'http_503' => 503,
                };
                return Http::response(['status_message' => 'Provider error'], $status);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:40:20');
        $this->postJson(
            route('midtrans.webhook.subscription'),
            syntheticLegacyInactivePayload($sub->subscription_id)
        )->assertStatus(503)->assertJson(['message' => 'Service unavailable']);

        expect($callbackHits)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
})->with(['connection_exception', 'http_404', 'http_500', 'http_503']);

test('e5.1.8b h10: malformed legacy subscription.inactive payloads return 400 and never enter recurring charge handler', function () {
    Carbon::setTestNow('2026-11-25 17:45:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
        ]);
        $before = snapshotSubscriptionState($sub);

        $malformedPayloads = [
            ['event_name' => 'subscription.inactive'],
            ['event_name' => 'subscription.inactive', 'subscription' => ['status' => 'inactive']],
            ['event_name' => 'subscription.inactive', 'subscription' => ['id' => '   ', 'status' => 'inactive']],
            ['event_name' => 'subscription.inactive', 'subscription' => ['id' => ['nested'], 'status' => 'inactive']],
            ['event_name' => 'subscription.inactive', 'subscription' => ['id' => 12345, 'status' => 'inactive']],
            ['event_name' => 'subscription.inactive', 'subscription' => ['id' => true, 'status' => 'inactive']],
            ['event_name' => 'subscription.inactive', 'subscription_id' => '   '],
            ['event_name' => 'subscription.inactive', 'subscription_id' => 12345],
            ['event_name' => 'subscription.inactive', 'subscription_id' => [$sub->subscription_id]],
            ['event_name' => 'subscription.inactive', 'subscription' => 'invalid-string'],
            ['event_name' => 'subscription.inactive', 'subscription' => ['not-an-assoc-object']],
            ['event_name' => 'subscription.inactive', 'subscription' => []],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
                'subscription_id' => 'different-flat-id',
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'disabled'],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'expired'],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'pending'],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => ['inactive']],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
                'transaction' => null,
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
                'transaction' => ['not-assoc'],
            ],
            [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
                'transaction' => [],
            ],
        ];

        Carbon::setTestNow('2026-11-25 17:45:10');
        foreach ($malformedPayloads as $payload) {
            $this->postJson(route('midtrans.webhook.subscription'), $payload)
                ->assertStatus(400)
                ->assertJson(['message' => 'Invalid payload']);
        }

        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNothingSent();
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h11: unknown subscription for legacy subscription.inactive returns 200 without provider GET or DB writes', function () {
    $unknownId = 'unknown-legacy-sub-' . Str::random(8);

    $this->postJson(
        route('midtrans.webhook.subscription'),
        syntheticLegacyInactivePayload($unknownId)
    )->assertStatus(200)->assertJson(['message' => 'OK']);

    $this->postJson(
        route('midtrans.webhook.subscription'),
        ['event_name' => 'subscription.inactive', 'subscription_id' => $unknownId]
    )->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Subscription::count())->toBe(0);
    expect(Donation::count())->toBe(0);
    Http::assertNothingSent();
});

test('e5.1.8b h12: invalid legacy signature is rejected with 403 and valid signature never bypasses provider GET', function () {
    Carbon::setTestNow('2026-11-25 17:50:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $orderId = 'SUB-INACT-SIG-CHECK-001';
        $validSig = makeRecurringSignature($orderId, '200', '10000.00');

        // 1. Invalid signature in transaction object -> 403 without provider call
        $this->postJson(route('midtrans.webhook.subscription'), syntheticLegacyInactivePayload($sub->subscription_id, [
            'transaction' => [
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'signature_key' => 'forged-signature-value',
            ],
        ]))->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

        // 2. Invalid signature at root -> 403 without provider call
        $this->postJson(route('midtrans.webhook.subscription'), [
            'event_name' => 'subscription.inactive',
            'subscription_id' => $sub->subscription_id,
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'signature_key' => 'forged-root-signature',
        ])->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

        Http::assertNothingSent();

        // 3. Valid signature NEVER bypasses provider GET verification -> provider amount mismatch fails closed 422
        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '99999']), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-25 17:50:20');
        $this->postJson(route('midtrans.webhook.subscription'), syntheticLegacyInactivePayload($sub->subscription_id, [
            'transaction' => [
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'signature_key' => $validSig,
            ],
        ]))->assertStatus(422)->assertJson(['message' => 'Provider subscription verification failed']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b h13: flat subscription_id and nested payloads without subscription.status enter consolidated ACK-only handler', function (string $shape) {
    Carbon::setTestNow('2026-11-25 17:55:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $shapeBody = match ($shape) {
            'flat_id_only' => ['subscription_id' => $sub->subscription_id],
            'flat_id_with_transaction_metadata' => [
                'subscription_id' => $sub->subscription_id,
                'order_id' => 'LEGACY-FLAT-001',
                'status_code' => '200',
                'transaction_status' => 'deny',
                'gross_amount' => '10000.00',
            ],
            'nested_without_status_with_transaction' => [
                'subscription' => ['id' => $sub->subscription_id],
                'transaction' => [
                    'order_id' => 'LEGACY-NESTED-NO-STATUS-001',
                    'status_code' => '200',
                    'transaction_status' => 'deny',
                ],
            ],
        };
        $payload = array_merge(['event_name' => 'subscription.inactive'], $shapeBody);

        Carbon::setTestNow('2026-11-25 17:55:20');
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'flat_id_only',
    'flat_id_with_transaction_metadata',
    'nested_without_status_with_transaction',
]);

test('e5.1.8b h14: legacy subscription.inactive preserves financial integrity and never fabricates cancellation or donations', function () {
    Carbon::setTestNow('2026-11-25 18:00:00');
    try {
        ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ], ['terkumpul_nominal' => 10000]);

        $existingDonation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'is_anonim' => false,
            'nominal' => 10000,
            'is_recurring' => true,
            'frequency' => 'mingguan',
            'order_id' => 'INIT-SUB-FIN-001',
            'subscription_id' => $sub->subscription_id,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'settled',
            'idempotency_key' => Str::uuid()->toString(),
            'tanggal_diterima' => now(),
        ]);

        $beforeSub = snapshotSubscriptionState($sub);
        $beforeDonationUpdatedAt = $existingDonation->fresh()->updated_at?->toIso8601String();

        $orderId = 'LEGACY-SETTLE-TX-001';
        $sig = makeRecurringSignature($orderId, '200', '10000.00');

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $progressMock = \Mockery::mock(CampaignProgressService::class);
        $progressMock->shouldNotReceive('recalculate');
        $this->app->instance(CampaignProgressService::class, $progressMock);

        Carbon::setTestNow('2026-11-25 18:00:30');
        $this->postJson(route('midtrans.webhook.subscription'), syntheticLegacyInactivePayload($sub->subscription_id, [
            'transaction' => [
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
                'signature_key' => $sig,
            ],
        ]))->assertStatus(422)->assertJson(['message' => 'Unsupported legacy payment evidence']);

        expect($getCalls)->toBe(1);
        expect(Donation::count())->toBe(1);
        $freshDonation = $existingDonation->fresh();
        expect($freshDonation->status)->toBe('terverifikasi');
        expect((int) $freshDonation->nominal)->toBe(10000);
        expect($freshDonation->updated_at?->toIso8601String())->toBe($beforeDonationUpdatedAt);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(10000);

        $freshSub = $sub->fresh();
        expect(isset($freshSub->metadata['cancellation']))->toBeFalse();
        expect($freshSub->management_state)->not->toBe('cancelled');
        expect(snapshotSubscriptionState($sub))->toBe($beforeSub);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================
// 16. E5.1.8B.1: B1 ROOT/NESTED STATUS CONSISTENCY & B2 PAYMENT EVIDENCE AMBIGUITY
// ============================================================

test('e5.1.8b.1 b1: accepts valid flat, nested, normalized, and alias legacy statuses with 200 ACK-only', function (Closure $payloadBuilder) {
    Carbon::setTestNow('2026-11-26 01:00:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        Carbon::setTestNow('2026-11-26 01:00:15');
        $payload = $payloadBuilder($sub->subscription_id);

        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(200)
            ->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'nested_status_without_root' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive']]],
    'flat_root_inactive' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'status' => 'inactive']],
    'flat_root_cancelled' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'status' => 'cancelled']],
    'root_status_with_nested_id_only' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id], 'status' => 'inactive']],
    'matching_root_and_nested_inactive' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'inactive']],
    'matching_case_and_whitespace_normalized' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => '  INACTIVE '], 'status' => "\tinactive\n"]],
    'equivalent_cancelled_and_canceled_spellings' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'canceled'], 'status' => 'cancelled']],
    'equivalent_canceled_and_cancelled_case_whitespace' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => ' Cancelled '], 'status' => ' CANCELED ']],
    'both_status_fields_absent_nested_id' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id]]],
    'both_status_fields_absent_flat_id' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id]],
]);

test('e5.1.8b.1 b1: rejects conflicting or malformed root status with 400 before provider GET and zero mutations', function (Closure $payloadBuilder) {
    Carbon::setTestNow('2026-11-26 01:05:00');
    try {
        $guardOwner = Str::uuid()->toString();
        ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
            'operation_guard_at' => now(),
            'operation_guard_owner' => $guardOwner,
            'metadata' => ['token_status' => 'verified_saved_token'],
        ], ['terkumpul_nominal' => 10000]);

        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'is_anonim' => false,
            'nominal' => 10000,
            'is_recurring' => false,
            'frequency' => 'mingguan',
            'order_id' => 'INIT-B1-GUARD-001',
            'subscription_id' => $sub->subscription_id,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activated',
            'idempotency_key' => Str::uuid()->toString(),
            'tanggal_diterima' => now(),
        ]);

        $beforeSub = snapshotSubscriptionState($sub);
        $beforeDonationUpdatedAt = $donation->fresh()->updated_at?->toIso8601String();

        Carbon::setTestNow('2026-11-26 01:05:15');
        $payload = $payloadBuilder($sub->subscription_id);

        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(400)
            ->assertJson(['message' => 'Invalid payload']);

        Http::assertNothingSent();
        expect(snapshotSubscriptionState($sub))->toBe($beforeSub);
        expect(Donation::count())->toBe(1);
        expect($donation->fresh()->updated_at?->toIso8601String())->toBe($beforeDonationUpdatedAt);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(10000);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'conflicting_root_active_nested_inactive' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'active']],
    'conflicting_root_cancelled_nested_inactive' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'cancelled']],
    'conflicting_root_canceled_nested_inactive' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'canceled']],
    'conflicting_root_inactive_nested_cancelled' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'cancelled'], 'status' => 'inactive']],
    'conflicting_root_inactive_nested_canceled' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'canceled'], 'status' => 'inactive']],
    'malformed_root_null_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => null]],
    'malformed_root_bool_true_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => true]],
    'malformed_root_bool_false_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => false]],
    'malformed_root_int_zero_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 0]],
    'malformed_root_int_positive_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 123]],
    'malformed_root_array_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => ['inactive']]],
    'malformed_root_object_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => ['value' => 'inactive']]],
    'malformed_root_empty_string_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => '']],
    'malformed_root_whitespace_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => "  \t "]],
    'unsupported_root_pending_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'pending']],
    'unsupported_root_disabled_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'disabled']],
    'unsupported_root_expired_with_valid_nested' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status' => 'expired']],
    'flat_explicit_null_root_status' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'status' => null]],
    'flat_unsupported_active_root_status' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'status' => 'active']],
]);

test('e5.1.8b.1 b2: fails closed with 422 and sanitized warning for ambiguous payment-bearing legacy payloads', function (Closure $payloadBuilder, string $expectedIndicator, bool $expectedHasSignature) {
    Carbon::setTestNow('2026-11-26 01:10:00');
    try {
        ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'paused',
            'token' => 'secret-saved-token-must-not-log',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ], ['terkumpul_nominal' => 10000]);

        $existingDonation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'is_anonim' => false,
            'nominal' => 10000,
            'is_recurring' => false,
            'frequency' => 'mingguan',
            'order_id' => 'INIT-B2-001',
            'subscription_id' => $sub->subscription_id,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activated',
            'idempotency_key' => Str::uuid()->toString(),
            'tanggal_diterima' => now(),
        ]);

        $beforeSub = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        \Illuminate\Support\Facades\Log::spy();

        Carbon::setTestNow('2026-11-26 01:10:20');
        $payload = $payloadBuilder($sub->subscription_id);

        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'Unsupported legacy payment evidence']);

        expect($getCalls)->toBe(1);
        expect(snapshotSubscriptionState($sub))->toBe($beforeSub);
        expect(Donation::count())->toBe(1);
        expect($existingDonation->fresh()->status)->toBe('terverifikasi');
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(10000);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($sub, $expectedIndicator, $expectedHasSignature): bool {
                $serialized = json_encode($context);
                return str_contains($message, 'ambiguous legacy payment evidence rejected')
                    && ($context['reason'] ?? null) === 'legacy_payment_evidence_ambiguous'
                    && ($context['indicator'] ?? null) === $expectedIndicator
                    && ($context['subscription_id'] ?? null) === $sub->subscription_id
                    && ($context['subscription_known'] ?? null) === true
                    && ($context['has_signature'] ?? null) === $expectedHasSignature
                    && !array_key_exists('payload', $context)
                    && !array_key_exists('signature_key', $context)
                    && !array_key_exists('token', $context)
                    && !str_contains((string) $serialized, 'secret-saved-token-must-not-log')
                    && !str_contains((string) $serialized, 'TEST_ONLY_SIGNATURE_KEY');
            });
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'nested_settlement_with_valid_signature' => [
        function (string $id): array {
            $orderId = 'LEG-B2-SIG-001';
            return [
                'event_name' => 'subscription.inactive',
                'subscription' => ['id' => $id, 'status' => 'inactive'],
                'transaction' => [
                    'order_id' => $orderId,
                    'status_code' => '200',
                    'gross_amount' => '10000.00',
                    'transaction_status' => 'settlement',
                    'signature_key' => makeRecurringSignature($orderId, '200', '10000.00'),
                ],
            ];
        },
        'transaction_status_settlement',
        true,
    ],
    'nested_settlement_without_signature' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'LEG-B2-NOSIG-001',
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
            ],
        ],
        'transaction_status_settlement',
        false,
    ],
    'root_settlement_with_valid_signature' => [
        function (string $id): array {
            $orderId = 'LEG-B2-ROOT-SIG-001';
            return [
                'event_name' => 'subscription.inactive',
                'subscription_id' => $id,
                'status' => 'inactive',
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
                'signature_key' => makeRecurringSignature($orderId, '200', '10000.00'),
            ];
        },
        'transaction_status_settlement',
        true,
    ],
    'root_settlement_without_signature' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription_id' => $id,
            'transaction_status' => 'settlement',
        ],
        'transaction_status_settlement',
        false,
    ],
    'nested_capture_with_fraud_accept' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'LEG-B2-CAP-ACCEPT-001',
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'capture',
                'fraud_status' => 'accept',
            ],
        ],
        'transaction_status_capture',
        false,
    ],
    'nested_capture_without_fraud_status' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'LEG-B2-CAP-NULL-001',
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'capture',
            ],
        ],
        'transaction_status_capture',
        false,
    ],
    'root_capture_with_fraud_challenge' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription_id' => $id,
            'transaction_status' => 'capture',
            'fraud_status' => 'challenge',
        ],
        'transaction_status_capture',
        false,
    ],
    'contradictory_root_settlement_nested_deny' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction_status' => 'settlement',
            'transaction' => ['transaction_status' => 'deny'],
        ],
        'conflicting_transaction_status_with_success',
        false,
    ],
    'contradictory_root_deny_nested_settlement' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction_status' => 'deny',
            'transaction' => ['transaction_status' => 'settlement'],
        ],
        'conflicting_transaction_status_with_success',
        false,
    ],
    'contradictory_settlement_with_non_200_status_code' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'LEG-B2-CONFLICT-CODE-001',
                'status_code' => '411',
                'transaction_status' => 'settlement',
            ],
        ],
        'conflicting_success_status_code',
        false,
    ],
    'contradictory_deny_with_fraud_accept' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'transaction_status' => 'deny',
                'fraud_status' => 'accept',
            ],
        ],
        'fraud_status_accept',
        false,
    ],
    'contradictory_deny_with_settlement_time' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'transaction' => [
                'transaction_status' => 'deny',
                'settlement_time' => '2026-11-26 01:00:00',
            ],
        ],
        'settlement_time_present',
        false,
    ],
    'contradictory_root_accept_nested_deny_fraud_status' => [
        fn (string $id) => [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $id, 'status' => 'inactive'],
            'fraud_status' => 'accept',
            'transaction' => ['fraud_status' => 'deny'],
        ],
        'conflicting_fraud_status_with_accept',
        false,
    ],
]);

test('e5.1.8b.1 b2: unknown subscription rejects ambiguous payment evidence with 422 without provider GET while status-only unknown still returns 200', function () {
    $unknownId = 'unknown-b2-sub-' . Str::random(8);
    \Illuminate\Support\Facades\Log::spy();

    // 1. Unknown subscription + settlement evidence -> 422, 0 HTTP calls
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $unknownId, 'status' => 'inactive'],
        'transaction' => [
            'order_id' => 'UNK-SETTLE-001',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => 'settlement',
        ],
    ])->assertStatus(422)->assertJson(['message' => 'Unsupported legacy payment evidence']);

    // 2. Unknown subscription + root capture evidence -> 422, 0 HTTP calls
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription_id' => $unknownId,
        'transaction_status' => 'capture',
    ])->assertStatus(422)->assertJson(['message' => 'Unsupported legacy payment evidence']);

    // 3. Unknown subscription + status-only -> 200 OK, 0 HTTP calls
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription_id' => $unknownId,
        'status' => 'inactive',
    ])->assertStatus(200)->assertJson(['message' => 'OK']);

    Http::assertNothingSent();
    expect(Subscription::count())->toBe(0);
    expect(Donation::count())->toBe(0);

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->twice()
        ->withArgs(fn (string $msg, array $ctx) => ($ctx['reason'] ?? null) === 'legacy_payment_evidence_ambiguous'
            && ($ctx['subscription_known'] ?? null) === false);
});

test('e5.1.8b.1 b2: invalid signature with successful payment evidence returns 403 before provider GET', function () {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);
    $before = snapshotSubscriptionState($sub);

    // Known subscription with forged signature + settlement -> 403, 0 HTTP calls
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'order_id' => 'LEG-FORGED-SETTLE-001',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'forged-signature-key',
        ],
    ])->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    // Unknown subscription with forged root signature + settlement -> 403, 0 HTTP calls
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription_id' => 'unknown-forged-sub',
        'order_id' => 'LEG-FORGED-SETTLE-002',
        'status_code' => '200',
        'gross_amount' => '10000.00',
        'transaction_status' => 'settlement',
        'signature_key' => 'forged-root-signature',
    ])->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    Http::assertNothingSent();
    expect(snapshotSubscriptionState($sub))->toBe($before);
    expect(Donation::count())->toBe(0);
});

test('e5.1.8b.1 b2: missing signing secret with signed payment evidence returns 503 before provider GET', function () {
    Config::set('services.midtrans.signature_secret_key', '');
    ['subscription' => $sub] = createActiveSubscription();

    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'order_id' => 'LEG-NOSECRET-001',
            'status_code' => '200',
            'gross_amount' => '100000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'any-signature',
        ],
    ])->assertStatus(503)->assertJson(['message' => 'Service unavailable']);

    Http::assertNothingSent();
    expect(Donation::count())->toBe(0);
});

test('e5.1.8b.1 b2: malformed payment fields or contradictory non-success metadata return 400 before provider GET', function (Closure $payloadBuilder) {
    ['subscription' => $sub] = createActiveSubscription();
    $before = snapshotSubscriptionState($sub);

    $this->postJson(route('midtrans.webhook.subscription'), $payloadBuilder($sub->subscription_id))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid payload']);

    Http::assertNothingSent();
    expect(snapshotSubscriptionState($sub))->toBe($before);
    expect(Donation::count())->toBe(0);
})->with([
    'non_string_transaction_status' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['transaction_status' => ['settlement']]]],
    'null_transaction_status' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction_status' => null]],
    'non_string_fraud_status' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'fraud_status' => ['accept']]],
    'non_string_settlement_time' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'settlement_time' => 12345]],
    'conflicting_non_success_transaction_statuses' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction_status' => 'deny', 'transaction' => ['transaction_status' => 'expire']]],
    'conflicting_order_ids_without_success' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'order_id' => 'ORD-A', 'transaction' => ['order_id' => 'ORD-B', 'transaction_status' => 'deny']]],
]);

test('e5.1.8b.1 b2: legacy transaction and signature metadata without successful-payment evidence still acknowledges 200 ACK-only', function () {
    Carbon::setTestNow('2026-11-26 01:15:00');
    try {
        ['subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'active',
            'management_state' => 'active',
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $orderId = 'LEG-META-ONLY-001';
        $sig = makeRecurringSignature($orderId, '200', '10000.00');

        // Metadata + valid signature without transaction_status/fraud_status/settlement_time -> 200
        $this->postJson(route('midtrans.webhook.subscription'), [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'signature_key' => $sig,
            ],
        ])->assertStatus(200)->assertJson(['message' => 'OK']);

        // Metadata + transaction_status: deny -> 200
        $this->postJson(route('midtrans.webhook.subscription'), [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => $orderId,
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'deny',
                'signature_key' => $sig,
            ],
        ])->assertStatus(200)->assertJson(['message' => 'OK']);

        expect($getCalls)->toBe(2);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b.1 b2: repeated ambiguous notifications fail closed consistently and preserve all records', function () {
    Carbon::setTestNow('2026-11-26 01:20:00');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
            'nominal' => 10000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'metadata' => [
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
            ],
        ]);
        $before = snapshotSubscriptionState($sub);

        $getCalls = 0;
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
                $getCalls++;
                return Http::response(verifiedProviderPayloadFor($sub, 'inactive'), 200);
            },
        ]);

        $payload = [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
            'transaction' => [
                'order_id' => 'LEG-REPEAT-AMB-001',
                'status_code' => '200',
                'gross_amount' => '10000.00',
                'transaction_status' => 'settlement',
            ],
        ];

        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'Unsupported legacy payment evidence']);
        $this->postJson(route('midtrans.webhook.subscription'), $payload)
            ->assertStatus(422)
            ->assertJson(['message' => 'Unsupported legacy payment evidence']);

        expect($getCalls)->toBe(2);
        expect(snapshotSubscriptionState($sub))->toBe($before);
        expect(Donation::count())->toBe(0);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8b.1 b2: provider unavailable returns 503 and mismatched provider data returns 422 before ambiguity check for known subscription', function () {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);
    $before = snapshotSubscriptionState($sub);

    $payload = [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'order_id' => 'LEG-PROV-ERR-001',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => 'settlement',
        ],
    ];

    $getCalls = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
            $getCalls++;
            if ($getCalls === 1) {
                throw new ConnectionException('Provider timeout');
            }
            if ($getCalls === 2) {
                return Http::response(['status_message' => 'Upstream error'], 500);
            }
            return Http::response(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '99999']), 200);
        },
    ]);

    // 1. Provider unavailable (ConnectionException) -> 503
    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(503)
        ->assertJson(['message' => 'Service unavailable']);
    expect($getCalls)->toBe(1);

    // 2. Provider HTTP 500 -> 503
    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(503)
        ->assertJson(['message' => 'Service unavailable']);
    expect($getCalls)->toBe(2);

    // 3. Invalid provider data (amount mismatch) -> 422 Provider subscription verification failed
    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(422)
        ->assertJson(['message' => 'Provider subscription verification failed']);
    expect($getCalls)->toBe(3);

    expect(snapshotSubscriptionState($sub))->toBe($before);
    expect(Donation::count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

// ============================================================
// 17. E5.1.8C: RECURRING, STATUS & LEGACY AMOUNT VALIDATION HARDENING
// ============================================================

test('e5.1.8c recurring charge: rejects raw JSON integral float, scientific number, and un-trimmed whitespace amount', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $secret = config('services.midtrans.signature_secret_key');

    // 1. Raw JSON integral float 100000.0 -> signature input invalid -> 403 (or unsigned float -> 422); never accepted
    $rawFloatJson = sprintf(
        '{"event_name":"subscription.charge","subscription":{"id":"%s","status":"active","amount":"100000"},"transaction":{"transaction_id":"tx-raw-flt-1","order_id":"SUB-RAW-FLT-001","status_code":"200","transaction_status":"settlement","fraud_status":"accept","gross_amount":100000.0,"signature_key":"%s"}}',
        $sub->subscription_id,
        makeRecurringSignature('SUB-RAW-FLT-001', '200', '100000.0', $secret)
    );
    $this->call(
        'POST',
        route('midtrans.webhook.subscription'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawFloatJson
    )->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    // 2. Raw JSON scientific float 1e5 without signature -> 422 Amount mismatch (no Status API call)
    $rawSciJson = sprintf(
        '{"event_name":"subscription.charge","subscription":{"id":"%s","status":"active"},"transaction":{"transaction_id":"tx-raw-sci-1","order_id":"SUB-RAW-SCI-001","status_code":"200","transaction_status":"settlement","fraud_status":"accept","gross_amount":1e5}}',
        $sub->subscription_id
    );
    $this->call(
        'POST',
        route('midtrans.webhook.subscription'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawSciJson
    )->assertStatus(422)->assertJson(['message' => 'Amount mismatch']);

    // 3. Whitespace padded gross_amount preserved by middleware exclusion -> 422 Amount mismatch
    $wsAmount = ' 100000.00 ';
    $rawWsJson = json_encode([
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => [
            'transaction_id' => 'tx-raw-ws-1',
            'order_id' => 'SUB-RAW-WS-001',
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => $wsAmount,
            'signature_key' => makeRecurringSignature('SUB-RAW-WS-001', '200', $wsAmount, $secret),
        ],
    ]);
    $this->call(
        'POST',
        route('midtrans.webhook.subscription'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $rawWsJson
    )->assertStatus(422)->assertJson(['message' => 'Amount mismatch']);

    Http::assertNothingSent();
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('e5.1.8c recurring charge: rejects scientific string with valid sig, invalid Status API amount, fractional local nominal, and invalid fallback amount', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);

    // 1. Scientific string '1e5' with valid synthetic signature -> 422
    $sciPayload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id' => 'SUB-SCI-SIG-001',
        'gross_amount' => '1e5',
    ]);
    $this->postJson(route('midtrans.webhook.subscription'), $sciPayload)
        ->assertStatus(422)
        ->assertJson(['message' => 'Amount mismatch']);

    // 2. Unsigned settlement where Status API returns scientific notation '1e5' -> 422 Provider amount mismatch
    $unsignedOrderId = 'SUB-STAT-SCI-001';
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $unsignedOrderId . '/status' => Http::response([
            'transaction_status' => 'settlement',
            'status_code' => '200',
            'gross_amount' => '1e5',
            'order_id' => $unsignedOrderId,
            'currency' => 'IDR',
        ], 200),
    ]);
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => [
            'transaction_id' => 'tx-stat-sci-001',
            'order_id' => $unsignedOrderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'gross_amount' => '100000.00',
        ],
    ])->assertStatus(422)->assertJson(['message' => 'Provider amount mismatch']);

    // 3. Invalid nested subscription.amount fallback -> 422 Amount mismatch
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active', 'amount' => '00100000'],
        'transaction' => [
            'transaction_id' => 'tx-fallback-bad-001',
            'order_id' => 'SUB-FALLBACK-BAD-001',
            'status_code' => '200',
            'transaction_status' => 'settlement',
        ],
    ])->assertStatus(422)->assertJson(['message' => 'Amount mismatch']);

    // 4. Fractional local Subscription nominal ('100000.99') -> 422 Amount mismatch
    DB::table('subscriptions')->where('id', $sub->id)->update(['nominal' => '100000.99']);
    $sub->refresh();
    $fracLocalPayload = officialRecurringPayload([
        'subscription_id' => $sub->subscription_id,
        'order_id' => 'SUB-FRAC-LOCAL-001',
        'gross_amount' => '100000.00',
    ]);
    $this->postJson(route('midtrans.webhook.subscription'), $fracLocalPayload)
        ->assertStatus(422)
        ->assertJson(['message' => 'Amount mismatch']);

    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
});

test('e5.1.8c recurring charge: explicit malformed signature_key returns 403 and mismatched signature representation returns 403', function (mixed $badSig) {
    ['subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);

    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => [
            'transaction_id' => 'tx-malf-sig-001',
            'order_id' => 'SUB-MALF-SIG-001',
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'gross_amount' => '100000.00',
            'signature_key' => $badSig,
        ],
    ])->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    Http::assertNothingSent();
    expect(Donation::count())->toBe(0);
})->with([
    'empty string' => [''],
    'null' => [null],
    'boolean false' => [false],
    'array' => [['sig']],
    'whitespace only' => ['   '],
    'signed on 100000 instead of 100000.00' => [fn () => makeRecurringSignature('SUB-MALF-SIG-001', '200', '100000')],
]);

test('e5.1.8c status & legacy webhooks: accepts 10000 and 10000.00 provider amounts, rejects invalid provider or fractional local amounts with 422', function () {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);
    $before = snapshotSubscriptionState($sub);

    // 1. Accepted provider amounts '10000' and '10000.00' -> 200 OK
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '10000']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '10000.00']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '1e4']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => ' 10000 ']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '10000.']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '0010000']), 200)
            ->push(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '10000.00']), 200),
    ]);

    $this->postJson(route('midtrans.webhook.subscription'), sandboxStatusUpdatePayload($sub->subscription_id, 'inactive'))
        ->assertStatus(200);
    $this->postJson(route('midtrans.webhook.subscription'), syntheticLegacyInactivePayload($sub->subscription_id))
        ->assertStatus(200);

    // 2. Invalid provider amount representations -> 422
    foreach (range(1, 4) as $ignored) {
        $this->postJson(route('midtrans.webhook.subscription'), sandboxStatusUpdatePayload($sub->subscription_id, 'inactive'))
            ->assertStatus(422)
            ->assertJson(['message' => 'Provider subscription verification failed']);
    }

    // 3. Fractional local Subscription nominal -> 422
    DB::table('subscriptions')->where('id', $sub->id)->update(['nominal' => '10000.99']);
    $this->postJson(route('midtrans.webhook.subscription'), syntheticLegacyInactivePayload($sub->subscription_id))
        ->assertStatus(422)
        ->assertJson(['message' => 'Provider subscription verification failed']);

    expect($sub->fresh()->status)->toBe($before['status']);
    expect(Donation::count())->toBe(0);
});

test('e5.1.8c legacy b2-r1: rejects standalone malformed root, transaction, and subscription amount metadata with 400 before provider GET', function (Closure $payloadBuilder) {
    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);
    $before = snapshotSubscriptionState($sub);

    $this->postJson(route('midtrans.webhook.subscription'), $payloadBuilder($sub->subscription_id))
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid payload']);

    Http::assertNothingSent();
    expect(snapshotSubscriptionState($sub))->toBe($before);
    expect(Donation::count())->toBe(0);
})->with([
    'standalone root gross_amount scientific' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'gross_amount' => '1e4']],
    'standalone root gross_amount null' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'gross_amount' => null]],
    'standalone root gross_amount empty' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'gross_amount' => '']],
    'standalone root gross_amount whitespace' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription_id' => $id, 'gross_amount' => ' 10000 ']],
    'standalone nested tx gross_amount fractional' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['order_id' => 'LEG-1', 'gross_amount' => '10000.50']]],
    'standalone nested tx gross_amount array' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['gross_amount' => ['10000']]]],
    'standalone nested sub amount scientific' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive', 'amount' => '1e4']]],
    'standalone nested sub amount null' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive', 'amount' => null]]],
    'conflicting sub amount and tx gross_amount' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive', 'amount' => '10000'], 'transaction' => ['gross_amount' => '20000.00']]],
]);

// ============================================================
// 18. E5.1.8C.1 (C2): RECURRING CHARGE AMOUNT EXTRACTION & CONFLICT HARDENING
// ============================================================

test('e5.1.8c.1 c2: rejects explicit null, malformed fallback, secondary malformed, and conflicting recurring charge amounts without outbound HTTP or mutations', function (Closure $payloadBuilder, int $expectedStatus) {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);

    $payload = $payloadBuilder($sub->subscription_id);

    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus($expectedStatus);

    Http::assertNothingSent();
    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
})->with([
    'explicit null tx gross_amount with valid sub amount (unsigned)' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => '100000'],
            'transaction' => ['transaction_id' => 'tx-c2-1', 'order_id' => 'SUB-C2-NULL-1', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => null],
        ],
        422,
    ],
    'explicit null tx gross_amount with valid sub amount and signature' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => '100000'],
            'transaction' => [
                'transaction_id' => 'tx-c2-2', 'order_id' => 'SUB-C2-NULL-2', 'status_code' => '200',
                'transaction_status' => 'settlement', 'gross_amount' => null,
                'signature_key' => makeRecurringSignature('SUB-C2-NULL-2', '200', '100000'),
            ],
        ],
        403,
    ],
    'malformed tx gross_amount with valid sub amount' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => '100000'],
            'transaction' => ['transaction_id' => 'tx-c2-3', 'order_id' => 'SUB-C2-MAL-1', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '1e5'],
        ],
        422,
    ],
    'valid tx gross_amount with malformed secondary sub amount' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => '1e5'],
            'transaction' => ['transaction_id' => 'tx-c2-4', 'order_id' => 'SUB-C2-SEC-1', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00'],
        ],
        422,
    ],
    'valid tx gross_amount with explicit null secondary sub amount' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => null],
            'transaction' => ['transaction_id' => 'tx-c2-5', 'order_id' => 'SUB-C2-SEC-2', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00'],
        ],
        422,
    ],
    'conflicting root and nested tx gross_amounts' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'gross_amount' => '200000.00',
            'subscription' => ['id' => $id, 'status' => 'active'],
            'transaction' => ['transaction_id' => 'tx-c2-6', 'order_id' => 'SUB-C2-CONF-1', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00'],
        ],
        422,
    ],
    'conflicting tx gross_amount and subscription amount' => [
        fn (string $id) => [
            'event_name' => 'subscription.charge',
            'subscription' => ['id' => $id, 'status' => 'active', 'amount' => '200000'],
            'transaction' => ['transaction_id' => 'tx-c2-7', 'order_id' => 'SUB-C2-CONF-2', 'status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '100000.00'],
        ],
        422,
    ],
]);

test('e5.1.8c.1 c2: accepts equivalent representations and genuine absent fallback while enforcing raw signature representation', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);

    // 1. Equivalent representations (tx 100000.00 + sub 100000) signed with 100000 -> 403
    $orderId1 = 'SUB-C2-EQ-BADSIG';
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active', 'amount' => '100000'],
        'transaction' => [
            'transaction_id' => 'tx-c2-eq-1', 'order_id' => $orderId1, 'status_code' => '200',
            'transaction_status' => 'settlement', 'gross_amount' => '100000.00',
            'signature_key' => makeRecurringSignature($orderId1, '200', '100000'),
        ],
    ])->assertStatus(403)->assertJson(['message' => 'Invalid signature']);

    // 2. Equivalent representations (tx 100000.00 + sub 100000) signed with raw 100000.00 -> 200 OK
    $orderId2 = 'SUB-C2-EQ-OK';
    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $orderId2 . '/status' => Http::response([
            'transaction_id' => 'tx-c2-eq-2',
            'order_id' => $orderId2,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '100000.00',
            'currency' => 'IDR',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id, 'status' => 'active', 'transaction_ids' => [$orderId2],
        ], 200),
    ]);
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active', 'amount' => '100000'],
        'transaction' => [
            'transaction_id' => 'tx-c2-eq-2', 'order_id' => $orderId2, 'status_code' => '200',
            'transaction_status' => 'settlement', 'gross_amount' => '100000.00',
            'signature_key' => makeRecurringSignature($orderId2, '200', '100000.00'),
        ],
    ])->assertStatus(200)->assertJson(['message' => 'OK']);

    expect(Donation::where('order_id', $orderId2)->count())->toBe(1);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(100000);
});

// ============================================================
// E5.1.8D SECTION 12: DEFERRED LEGACY WEBHOOK CLOSE-OUT TESTS
// ============================================================

test('E5.1.8D: legacy inactive rejects standalone malformed order_id transaction_id and status_code with 400 and zero HTTP calls', function (callable $buildPayload) {
    Http::fake();
    $payload = $buildPayload('sub-sec12-badmeta-' . Str::random(6));

    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid payload']);

    Http::assertNothingSent();
})->with([
    'standalone empty root order_id' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'order_id' => '']],
    'standalone array nested order_id' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['order_id' => ['bad']]]],
    'standalone null nested transaction_id' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['transaction_id' => null]]],
    'standalone array status_code' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'transaction' => ['status_code' => ['200']]]],
    'standalone bool status_code' => [fn (string $id) => ['event_name' => 'subscription.inactive', 'subscription' => ['id' => $id, 'status' => 'inactive'], 'status_code' => true]],
]);

test('E5.1.8D: legacy inactive with ambiguous payment evidence logs stable ambiguity_reason and ambiguity_indicator when provider verification fails', function () {
    \Illuminate\Support\Facades\Log::spy();

    ['subscription' => $sub] = createActiveSubscription([
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
    ]);

    $getCall = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCall, $sub) {
            $getCall++;
            if ($getCall === 1) {
                return Http::response(['error' => 'upstream down'], 500);
            }
            return Http::response(verifiedProviderPayloadFor($sub, 'inactive', ['amount' => '99999']), 200);
        },
    ]);

    $payload = [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'order_id' => 'LEG-SEC12-LOG-001',
            'status_code' => '200',
            'gross_amount' => '10000.00',
            'transaction_status' => 'settlement',
        ],
    ];

    $this->postJson(route('midtrans.webhook.subscription'), $payload)->assertStatus(503);
    $this->postJson(route('midtrans.webhook.subscription'), $payload)->assertStatus(422);

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(function (string $msg, array $ctx) use ($sub) {
            return str_contains($msg, 'provider verification failed')
                && ($ctx['subscription_id'] ?? null) === $sub->subscription_id
                && ($ctx['ambiguity_reason'] ?? null) === 'legacy_payment_evidence_ambiguous'
                && ($ctx['ambiguity_indicator'] ?? null) === 'transaction_status_settlement';
        })
        ->once();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(function (string $msg, array $ctx) use ($sub) {
            return str_contains($msg, 'provider data mismatch')
                && ($ctx['subscription_id'] ?? null) === $sub->subscription_id
                && ($ctx['ambiguity_reason'] ?? null) === 'legacy_payment_evidence_ambiguous'
                && ($ctx['ambiguity_indicator'] ?? null) === 'transaction_status_settlement';
        })
        ->once();
});

// ============================================================
// E5.1 FINAL SIGN-OFF: SECTION 8.2 & SECTION 9 TESTS
// ============================================================

test('E5.1: 8.2 legacy inactive malformed order_id transaction_id or status_code returns 400 even with settlement capture fraud accept or settlement_time', function (callable $buildPayload) {
    Http::fake();
    $payload = $buildPayload('sub-82-prec-' . Str::random(6));

    $this->postJson(route('midtrans.webhook.subscription'), $payload)
        ->assertStatus(400)
        ->assertJson(['message' => 'Invalid payload']);

    expect(Donation::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'malformed order_id + settlement' => [fn (string $id) => [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $id, 'status' => 'inactive'],
        'transaction' => ['order_id' => '', 'transaction_status' => 'settlement', 'status_code' => '200', 'gross_amount' => '10000.00'],
    ]],
    'malformed status_code + settlement' => [fn (string $id) => [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $id, 'status' => 'inactive'],
        'transaction' => ['order_id' => 'LEG-82-SC', 'transaction_status' => 'settlement', 'status_code' => ['200'], 'gross_amount' => '10000.00'],
    ]],
    'malformed transaction_id + capture accept' => [fn (string $id) => [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $id, 'status' => 'inactive'],
        'transaction' => ['transaction_id' => ['bad'], 'transaction_status' => 'capture', 'fraud_status' => 'accept', 'status_code' => '200'],
    ]],
    'malformed status_code + fraud_status accept' => [fn (string $id) => [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $id, 'status' => 'inactive'],
        'transaction' => ['order_id' => 'LEG-82-FA', 'fraud_status' => 'accept', 'status_code' => false],
    ]],
    'malformed root order_id + settlement_time' => [fn (string $id) => [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $id, 'status' => 'inactive'],
        'order_id' => null,
        'settlement_time' => '2026-11-26 01:00:00',
    ]],
]);

test('E5.1: 9 recurring charge rejects non-IDR and malformed runtime currency types with 422', function (mixed $badCurrency) {
    Http::fake();
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $orderId = 'SUB-9-CURR-' . Str::random(6);

    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => [
            'transaction_id' => 'tx-9-' . Str::random(4),
            'order_id' => $orderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'gross_amount' => '100000.00',
            'currency' => $badCurrency,
            'signature_key' => makeRecurringSignature($orderId, '200', '100000.00'),
        ],
    ])->assertStatus(422)->assertJson(['message' => 'Invalid currency']);

    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
    Http::assertNothingSent();
})->with([
    'USD' => ['USD'],
    'empty string' => [''],
    'array' => [['IDR']],
    'bool' => [false],
    'int' => [0],
]);

// ============================================================
// E5.1-TZ CHECKPOINT TZ0.1: AUTHORITATIVE RECURRING TRANSACTION EVIDENCE TESTS
// ============================================================

test('TZ0: recurring webhook signed and unsigned settlement and capture accept verify authoritative Status API and Subscription linkage', function (string $mode) {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $orderId = 'SUB-TZ0-OK-' . strtoupper(Str::random(5));
    $txId = 'tx-tz0-ok-' . Str::random(5);
    $statusGetCalls = 0;
    $subGetCalls = 0;

    $isCapture = ($mode === 'capture_accept');
    $signed = ($mode !== 'unsigned_settlement');

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $orderId . '/status' => function () use ($orderId, $txId, $isCapture, &$statusGetCalls) {
            $statusGetCalls++;
            return Http::response([
                'transaction_id' => $txId,
                'order_id' => $orderId,
                'status_code' => '200',
                'transaction_status' => $isCapture ? 'capture' : 'settlement',
                'fraud_status' => 'accept',
                'gross_amount' => '100000.00',
                'currency' => 'IDR',
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $orderId, $txId, &$subGetCalls) {
            $subGetCalls++;
            return Http::response([
                'id' => $sub->subscription_id,
                'status' => 'active',
                'transaction_ids' => [$txId, $orderId],
            ], 200);
        },
    ]);

    $tx = [
        'transaction_id' => $txId,
        'order_id' => $orderId,
        'status_code' => '200',
        'transaction_status' => $isCapture ? 'capture' : 'settlement',
        'fraud_status' => 'accept',
        'gross_amount' => '100000.00',
    ];
    if ($signed) {
        $tx['signature_key'] = makeRecurringSignature($orderId, '200', '100000.00');
    }

    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => $tx,
    ])->assertStatus(200)->assertJson(['message' => 'OK']);

    expect($statusGetCalls)->toBe(1);
    expect($subGetCalls)->toBe(1);
    expect(Donation::where('order_id', $orderId)->where('status', 'terverifikasi')->count())->toBe(1);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(100000);
})->with([
    'signed settlement' => ['signed_settlement'],
    'unsigned settlement' => ['unsigned_settlement'],
    'capture accept' => ['capture_accept'],
]);

test('TZ0: recurring webhook signed success fails closed on unpaid status, mismatched identity, malformed linkage, wrong sub id, or API failure', function (string $scenario) {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription(['nominal' => 100000]);
    $orderId = 'SUB-TZ0-FAIL-' . strtoupper(Str::random(5));
    $txId = 'tx-tz0-fail-' . Str::random(5);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/' . $orderId . '/status' => function () use ($scenario, $orderId, $txId) {
            if ($scenario === 'status_api_unavailable') {
                return Http::response([], 503);
            }
            return Http::response([
                'transaction_id' => $scenario === 'tx_id_mismatch' ? 'tx-other-999' : $txId,
                'order_id' => $scenario === 'order_id_mismatch' ? 'OTHER-ORDER-999' : $orderId,
                'status_code' => '200',
                'transaction_status' => $scenario === 'not_paid' ? 'pending' : 'settlement',
                'fraud_status' => 'accept',
                'gross_amount' => $scenario === 'wrong_amount' ? '50000.00' : '100000.00',
                'currency' => $scenario === 'wrong_currency' ? 'USD' : 'IDR',
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($scenario, $sub) {
            if ($scenario === 'sub_api_unavailable') {
                throw new ConnectionException('Timeout');
            }
            $txIds = match ($scenario) {
                'null_linkage' => [null, ''],
                'malformed_linkage' => [123, true, ['nested']],
                default => [],
            };
            return Http::response([
                'id' => $scenario === 'wrong_sub_id' ? 'sub-completely-other' : $sub->subscription_id,
                'status' => 'active',
                'transaction_ids' => $txIds,
            ], 200);
        },
    ]);

    $response = $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'active'],
        'transaction' => [
            'transaction_id' => $txId,
            'order_id' => $orderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '100000.00',
            'signature_key' => makeRecurringSignature($orderId, '200', '100000.00'),
        ],
    ]);

    $expectedHttp = in_array($scenario, ['wrong_amount', 'wrong_currency'], true) ? 422 : 403;
    $response->assertStatus($expectedHttp);

    expect(Donation::count())->toBe(0);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(0);
})->with([
    'valid sig but pending on provider' => ['not_paid'],
    'mismatched authoritative order_id' => ['order_id_mismatch'],
    'mismatched authoritative transaction_id' => ['tx_id_mismatch'],
    'null/empty linkage entries' => ['null_linkage'],
    'malformed linkage entries' => ['malformed_linkage'],
    'wrong provider subscription identity' => ['wrong_sub_id'],
    'wrong authoritative amount' => ['wrong_amount'],
    'wrong authoritative currency' => ['wrong_currency'],
    'status api 503 unavailable' => ['status_api_unavailable'],
    'get subscription api timeout' => ['sub_api_unavailable'],
]);

test('TZ0: recurring webhook deduplicates across order_id and transaction_id representations and preserves late charge after cancel', function () {
    ['campaign' => $campaign, 'subscription' => $sub] = createActiveSubscription([
        'nominal' => 100000,
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => [
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'inactive',
                'confirmed_at' => '2026-11-25T16:39:06+00:00',
            ],
        ],
    ]);
    $orderId = 'SUB-TZ0-LATE-' . strtoupper(Str::random(5));
    $txId = 'tx-tz0-late-' . Str::random(5);

    Http::fake([
        'https://api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'transaction_id' => $txId,
            'order_id' => $orderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '100000.00',
            'currency' => 'IDR',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'status' => 'inactive',
            'transaction_ids' => [$orderId, $txId],
        ], 200),
    ]);

    // 1. Late verified recurring charge arrives with order_id after local cancel -> recorded once, cancelled state preserved!
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'transaction_id' => $txId,
            'order_id' => $orderId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '100000.00',
            'signature_key' => makeRecurringSignature($orderId, '200', '100000.00'),
        ],
    ])->assertStatus(200);

    // 2. Duplicate arrives identified only by transaction_id -> idempotent 200 without duplicate Donation!
    $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.charge',
        'subscription' => ['id' => $sub->subscription_id, 'status' => 'inactive'],
        'transaction' => [
            'transaction_id' => $txId,
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '100000.00',
            'signature_key' => makeRecurringSignature($txId, '200', '100000.00'),
        ],
    ])->assertStatus(200);

    expect(Donation::where('subscription_id', $sub->subscription_id)->count())->toBe(1);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(100000);
    expect($sub->fresh()->management_state)->toBe('cancelled');
});
