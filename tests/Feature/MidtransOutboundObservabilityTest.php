<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MidtransApiException;
use App\Services\MidtransConnectionException;
use App\Services\MidtransHttpObservability;
use App\Services\MidtransPaymentService;
use App\Services\SubscriptionManagementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $effectiveDb = DB::selectOne('SELECT DATABASE() AS db');
    if (($effectiveDb->db ?? null) !== 'wecare_testing') {
        throw new \RuntimeException('SAFETY VIOLATION: Tests must execute on wecare_testing, current DB: ' . ($effectiveDb->db ?? 'null'));
    }

    $this->obsLogPath = storage_path('logs/midtrans-outbound-test-' . Str::uuid()->toString() . '.log');
    if (file_exists($this->obsLogPath)) {
        @unlink($this->obsLogPath);
    }

    Config::set('logging.channels.midtrans_observability', [
        'driver' => 'single',
        'path' => $this->obsLogPath,
        'level' => 'info',
        'replace_placeholders' => false,
    ]);
    Log::forgetChannel('midtrans_observability');

    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY_999');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY_999');
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY_111');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.api_url', 'https://app.sandbox.midtrans.com/snap/v1');
    Config::set('services.midtrans.status_api_url', 'https://api.sandbox.midtrans.com/v2');
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
    Config::set('services.midtrans.observability.enabled', true);
    Config::set('services.midtrans.observability.channel', 'midtrans_observability');
    Config::set('services.midtrans.observability.allowed_environments', ['local', 'testing']);

    Http::preventStrayRequests();
});

afterEach(function () {
    Log::forgetChannel('midtrans_observability');
    if (!empty($this->obsLogPath) && file_exists($this->obsLogPath)) {
        @unlink($this->obsLogPath);
    }
});

function createObservabilityFixtures(array $subOverrides = [], array $donOverrides = []): array
{
    $donatur = User::factory()->donatur()->create([
        'name' => 'SYNTHETIC-DONOR-NAME',
        'email' => 'synthetic-donor-secret-' . Str::lower(Str::random(8)) . '@example.test',
    ]);
    $penerima = User::factory()->penerima()->create();

    $campaign = Campaign::create([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Observability Test',
        'slug' => 'campaign-observability-' . Str::random(8),
        'deskripsi' => 'Campaign mocked observability',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 1000000,
        'terkumpul_nominal' => 15000,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addMonth()->toDateString(),
        'status' => 'aktif',
    ]);

    $donation = Donation::create(array_merge([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'is_anonim' => false,
        'nominal' => 15000,
        'is_recurring' => true,
        'frequency' => 'mingguan',
        'order_id' => 'INIT-OBS-' . Str::upper(Str::random(10)),
        'status' => 'menunggu_pembayaran',
        'idempotency_key' => 'idemp-SYNTHETIC-555-' . Str::random(8),
    ], $donOverrides));

    $subscription = Subscription::create(array_merge([
        'subscription_id' => 'provider-sub-uuid-SYNTHETIC-444-' . Str::random(6),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 15000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
        'payment_type' => 'credit_card',
        'token' => 'saved-token-SYNTHETIC-SECRET-888',
        'status' => 'active',
        'management_state' => 'active',
        'is_anonim' => false,
        'idempotency_key' => 'sub-idemp-SYNTHETIC-SECRET-' . Str::random(6),
        'metadata' => [],
    ], $subOverrides));

    return compact('donatur', 'penerima', 'campaign', 'donation', 'subscription');
}

function parseObservabilityEntries(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entries = [];

    foreach ($lines as $line) {
        $jsonStart = strpos($line, '{');
        if ($jsonStart === false) {
            continue;
        }
        $decoded = json_decode(substr($line, $jsonStart), true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }

    return $entries;
}

test('default config keeps midtrans observability disabled and active database is wecare_testing', function () {
    $defaultConfig = require base_path('config/services.php');
    expect($defaultConfig['midtrans']['observability']['enabled'])->toBeFalse();
    expect($defaultConfig['midtrans']['observability']['allowed_environments'])->toBe(['local', 'testing']);

    $db = DB::selectOne('SELECT DATABASE() AS db');
    expect($db->db)->toBe('wecare_testing');
});

test('1. logs all 8 outbound Midtrans operations on HTTP 200 with fixed templates and attempt/result correlation', function () {
    $fx = createObservabilityFixtures();
    $providerSubId = $fx['subscription']->subscription_id;
    $orderId = $fx['donation']->order_id;

    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-token-SYNTHETIC-666',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-SYNTHETIC-666',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '200',
            'transaction_status' => 'capture',
            'fraud_status' => 'accept',
            'saved_token_id' => 'saved-token-SYNTHETIC-SECRET-888',
            'gross_amount' => '15000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id' => $providerSubId,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}" => Http::response([
            'id' => $providerSubId,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/disable" => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/enable" => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/cancel" => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
        "https://api.sandbox.midtrans.com/v2/{$orderId}/status" => Http::response([
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'gross_amount' => '15000.00',
            'order_id' => $orderId,
        ], 200),
    ]);

    $service = app(MidtransPaymentService::class);
    $service->createSnapTransaction($fx['donation'], $fx['donatur'], $fx['campaign']);
    $service->chargeInitialCardWithSaveToken($fx['donation'], 'card-token-SYNTHETIC-777', $fx['donatur'], $fx['campaign']);
    $service->createSubscription(
        $fx['donatur'],
        $fx['campaign'],
        15000,
        'mingguan',
        'credit_card',
        'saved-token-SYNTHETIC-SECRET-888',
        false,
        'idemp-SYNTHETIC-555',
        ['local_donation_id' => $fx['donation']->id, 'correlation_ref' => $orderId]
    );
    $service->getSubscription($providerSubId, $fx['subscription']->id);
    $service->disableSubscription($providerSubId, $fx['subscription']->id);
    $service->enableSubscription($providerSubId, $fx['subscription']->id);
    $service->cancelSubscription($providerSubId, $fx['subscription']->id);
    $service->checkTransactionStatus($orderId, $fx['donation']->id);

    Http::assertSentCount(8);

    $entries = parseObservabilityEntries($this->obsLogPath);
    expect($entries)->toHaveCount(16); // 8 attempt + 8 result

    $attempts = array_values(array_filter($entries, fn ($e) => $e['event_type'] === 'outbound_attempt'));
    $results = array_values(array_filter($entries, fn ($e) => $e['event_type'] === 'outbound_result'));
    expect($attempts)->toHaveCount(8);
    expect($results)->toHaveCount(8);

    $attemptIds = array_column($attempts, 'attempt_id');
    $resultIds = array_column($results, 'attempt_id');
    expect(count(array_unique($attemptIds)))->toBe(8);
    expect($attemptIds)->toBe($resultIds);

    $expectedTemplates = [
        'snap_create_transaction' => ['POST', '/snap/v1/transactions'],
        'card_initial_charge' => ['POST', '/v2/charge'],
        'subscription_create' => ['POST', '/v1/subscriptions'],
        'subscription_get' => ['GET', '/v1/subscriptions/{subscription_id}'],
        'subscription_disable' => ['POST', '/v1/subscriptions/{subscription_id}/disable'],
        'subscription_enable' => ['POST', '/v1/subscriptions/{subscription_id}/enable'],
        'subscription_cancel' => ['POST', '/v1/subscriptions/{subscription_id}/cancel'],
        'transaction_get_status' => ['GET', '/v2/{order_id}/status'],
    ];

    foreach ($results as $idx => $result) {
        $op = $result['operation'];
        expect($result['http_method'])->toBe($expectedTemplates[$op][0]);
        expect($result['endpoint_template'])->toBe($expectedTemplates[$op][1]);
        expect($result['http_status'])->toBe(200);
        expect($result['transport_outcome'])->toBe('http_2xx');
        expect($result['elapsed_ms'])->toBeGreaterThanOrEqual(0);
        expect($attempts[$idx]['transport_outcome'])->toBe('dispatched');
    }
});

test('2 & 3. classifies provider HTTP 4xx and 5xx responses while preserving exceptions', function () {
    $fx = createObservabilityFixtures();
    $providerSubId = $fx['subscription']->subscription_id;

    Http::fake([
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/disable" => Http::response([
            'status_message' => 'Validation error',
        ], 422),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/enable" => Http::response([
            'status_message' => 'Gateway error',
        ], 503),
    ]);

    $service = app(MidtransPaymentService::class);

    expect(fn () => $service->disableSubscription($providerSubId, $fx['subscription']->id))
        ->toThrow(MidtransApiException::class);

    expect(fn () => $service->enableSubscription($providerSubId, $fx['subscription']->id))
        ->toThrow(MidtransApiException::class);

    $entries = parseObservabilityEntries($this->obsLogPath);
    $results = array_values(array_filter($entries, fn ($e) => $e['event_type'] === 'outbound_result'));
    expect($results)->toHaveCount(2);

    expect($results[0]['operation'])->toBe('subscription_disable');
    expect($results[0]['http_status'])->toBe(422);
    expect($results[0]['transport_outcome'])->toBe('http_4xx');

    expect($results[1]['operation'])->toBe('subscription_enable');
    expect($results[1]['http_status'])->toBe(503);
    expect($results[1]['transport_outcome'])->toBe('http_5xx');
});

test('4 & 5. distinguishes connection error and timeout without leaking raw exception message', function () {
    $fx = createObservabilityFixtures();
    $providerSubId = $fx['subscription']->subscription_id;

    Http::fake([
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/disable" => function () {
            throw new ConnectionException('Connection refused to https://api.sandbox.midtrans.com/v1/subscriptions/LEAK-SUB-URL?secret=LEAK-TOKEN');
        },
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$providerSubId}/enable" => function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with Authorization: Basic LEAK-AUTH');
        },
    ]);

    $service = app(MidtransPaymentService::class);

    expect(fn () => $service->disableSubscription($providerSubId, $fx['subscription']->id))
        ->toThrow(MidtransConnectionException::class);

    expect(fn () => $service->enableSubscription($providerSubId, $fx['subscription']->id))
        ->toThrow(MidtransConnectionException::class);

    $entries = parseObservabilityEntries($this->obsLogPath);
    $results = array_values(array_filter($entries, fn ($e) => $e['event_type'] === 'outbound_result'));
    expect($results)->toHaveCount(2);

    expect($results[0]['http_status'])->toBeNull();
    expect($results[0]['transport_outcome'])->toBe('transport_connection_error');

    expect($results[1]['http_status'])->toBeNull();
    expect($results[1]['transport_outcome'])->toBe('transport_timeout');
    expect($results[1]['transport_outcome'])->not->toBe('http_4xx');

    $rawLog = file_get_contents($this->obsLogPath);
    expect($rawLog)->not->toContain('LEAK-SUB-URL');
    expect($rawLog)->not->toContain('LEAK-TOKEN');
    expect($rawLog)->not->toContain('LEAK-AUTH');
    expect($rawLog)->not->toContain('Connection refused');
    expect($rawLog)->not->toContain('cURL error 28');
});

test('6. disabled observability configuration emits zero log records', function () {
    Config::set('services.midtrans.observability.enabled', false);
    $fx = createObservabilityFixtures();

    Http::fake([
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fx['subscription']->subscription_id}" => Http::response([
            'id' => $fx['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
        ], 200),
    ]);

    $service = app(MidtransPaymentService::class);
    $res = $service->getSubscription($fx['subscription']->subscription_id, $fx['subscription']->id);

    expect($res['status'])->toBe('active');
    expect(file_exists($this->obsLogPath))->toBeFalse();
});

test('7. non-Sandbox production mode or disallowed app environment suppresses logging', function () {
    $fx = createObservabilityFixtures();

    Http::fake([
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fx['subscription']->subscription_id}" => Http::response([
            'id' => $fx['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
        ], 200),
        "https://api.midtrans.com/v1/subscriptions/{$fx['subscription']->subscription_id}" => Http::response([
            'id' => $fx['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
        ], 200),
    ]);

    $service = app(MidtransPaymentService::class);

    // 7a. Production flag enabled -> suppressed
    Config::set('services.midtrans.is_production', true);
    $service->getSubscription($fx['subscription']->subscription_id, $fx['subscription']->id);
    expect(file_exists($this->obsLogPath))->toBeFalse();

    // 7b. Allowed environments mismatch -> suppressed
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.observability.allowed_environments', ['local']);
    $service->getSubscription($fx['subscription']->subscription_id, $fx['subscription']->id);
    expect(file_exists($this->obsLogPath))->toBeFalse();
});

test('8 & 9. exactly one attempt record per dispatch and no logging on pre-dispatch validation errors', function () {
    $fx = createObservabilityFixtures();
    $service = app(MidtransPaymentService::class);

    // Pre-dispatch validation rejection should emit zero HTTP calls and zero logs
    expect(fn () => $service->createSubscription(
        $fx['donatur'],
        $fx['campaign'],
        15000,
        'tahunan',
        'credit_card',
        'saved-token-SYNTHETIC-SECRET-888'
    ))->toThrow(MidtransApiException::class);

    Http::assertNothingSent();
    expect(file_exists($this->obsLogPath))->toBeFalse();
});

test('10, 11, 12. repeated Pause, Resume, and Cancel emit zero duplicate mutation dispatches', function () {
    $fxPaused = createObservabilityFixtures([
        'status' => 'inactive',
        'management_state' => 'paused',
    ]);
    $fxActive = createObservabilityFixtures([
        'status' => 'active',
        'management_state' => 'active',
    ]);
    $fxCancelled = createObservabilityFixtures([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => [
            'cancellation' => [
                'confirmed_at' => '2026-11-26T00:00:00+00:00',
                'provider_status' => 'inactive',
                'provider_confirmed' => true,
            ],
        ],
    ]);

    Http::fake([
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fxPaused['subscription']->subscription_id}" => Http::response([
            'id' => $fxPaused['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fxActive['subscription']->subscription_id}" => Http::response([
            'id' => $fxActive['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fxCancelled['subscription']->subscription_id}" => Http::response([
            'id' => $fxCancelled['subscription']->subscription_id,
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
    ]);

    $mgmt = app(SubscriptionManagementService::class);
    $pauseRes = $mgmt->pause($fxPaused['subscription']);
    $resumeRes = $mgmt->resume($fxActive['subscription']);
    $cancelRes = $mgmt->cancel($fxCancelled['subscription']);

    expect($pauseRes['success'])->toBeTrue();
    expect($resumeRes['success'])->toBeTrue();
    expect($cancelRes['success'])->toBeTrue();

    // Exactly 3 GETs, zero disable/enable/cancel POSTs
    Http::assertSentCount(3);

    $entries = parseObservabilityEntries($this->obsLogPath);
    $attempts = array_values(array_filter($entries, fn ($e) => $e['event_type'] === 'outbound_attempt'));
    expect($attempts)->toHaveCount(3);
    expect(array_column($attempts, 'operation'))->toBe([
        'subscription_get',
        'subscription_get',
        'subscription_get',
    ]);
    expect(array_column($attempts, 'local_subscription_id'))->toBe([
        $fxPaused['subscription']->id,
        $fxActive['subscription']->id,
        $fxCancelled['subscription']->id,
    ]);
});

test('13. strict redaction check confirms zero secrets, tokens, URLs, guard owners, or PII appear in logs', function () {
    $guardOwnerUuid = 'guard-uuid-SYNTHETIC-999-SECRET';
    $fx = createObservabilityFixtures([
        'subscription_id' => 'provider-sub-SECRET-UUID-444',
        'token' => 'saved-token-SYNTHETIC-SECRET-888',
        'operation_guard_owner' => $guardOwnerUuid,
    ], [
        'idempotency_key' => 'idemp-SYNTHETIC-555',
    ]);

    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-token-SYNTHETIC-666',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-SYNTHETIC-666',
        ], 200),
        'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
            'status_code' => '200',
            'transaction_status' => 'capture',
            'fraud_status' => 'accept',
            'saved_token_id' => 'saved-token-SYNTHETIC-SECRET-888',
            'gross_amount' => '15000.00',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response([
            'id' => 'provider-sub-SECRET-UUID-444',
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/provider-sub-SECRET-UUID-444' => Http::response([
            'id' => 'provider-sub-SECRET-UUID-444',
            'amount' => '15000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
    ]);

    $service = app(MidtransPaymentService::class);
    $service->createSnapTransaction($fx['donation'], $fx['donatur'], $fx['campaign']);
    $service->chargeInitialCardWithSaveToken($fx['donation'], 'card-token-SYNTHETIC-777', $fx['donatur'], $fx['campaign']);
    $service->createSubscription(
        $fx['donatur'],
        $fx['campaign'],
        15000,
        'mingguan',
        'credit_card',
        'saved-token-SYNTHETIC-SECRET-888',
        false,
        'idemp-SYNTHETIC-555',
        ['local_donation_id' => $fx['donation']->id, 'correlation_ref' => $fx['donation']->order_id]
    );
    $service->getSubscription('provider-sub-SECRET-UUID-444', $fx['subscription']->id);

    $rawLog = file_get_contents($this->obsLogPath);
    $forbiddenStrings = [
        'TEST_ONLY_SERVER_KEY_999',
        'TEST_ONLY_CLIENT_KEY_999',
        'TEST_ONLY_SIGNATURE_KEY_111',
        'Basic ',
        'https://app.sandbox.midtrans.com',
        'https://api.sandbox.midtrans.com',
        'provider-sub-SECRET-UUID-444',
        'saved-token-SYNTHETIC-SECRET-888',
        'card-token-SYNTHETIC-777',
        'snap-token-SYNTHETIC-666',
        'idemp-SYNTHETIC-555',
        $guardOwnerUuid,
        'SYNTHETIC-DONOR-NAME',
        $fx['donatur']->email,
        'synthetic-donor-secret',
        'transaction_details',
        'saved_token_id',
        'Authorization',
    ];

    foreach ($forbiddenStrings as $forbidden) {
        expect($rawLog)->not->toContain($forbidden);
    }

    $allowedKeys = [
        'event_type',
        'attempt_id',
        'operation',
        'http_method',
        'endpoint_template',
        'http_status',
        'elapsed_ms',
        'local_subscription_id',
        'local_donation_id',
        'correlation_ref',
        'transport_outcome',
        'timestamp_utc',
    ];

    foreach (parseObservabilityEntries($this->obsLogPath) as $entry) {
        expect(array_keys($entry))->toBe($allowedKeys);
    }
});

test('14. logging sink failure does not alter Snap or Subscription Management outcomes', function () {
    $fx = createObservabilityFixtures([
        'status' => 'active',
        'management_state' => 'active',
    ]);

    Http::fake([
        'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
            'token' => 'snap-token-SYNTHETIC-666',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-SYNTHETIC-666',
        ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fx['subscription']->subscription_id}" => Http::sequence()
            ->push([
                'id' => $fx['subscription']->subscription_id,
                'amount' => '15000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
            ], 200)
            ->push([
                'id' => $fx['subscription']->subscription_id,
                'amount' => '15000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'week', 'next_execution_at' => '2026-12-03T06:00:00Z'],
            ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$fx['subscription']->subscription_id}/disable" => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    Log::shouldReceive('channel')
        ->with('midtrans_observability')
        ->andThrow(new \RuntimeException('Simulated log sink disk full'));
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('forgetChannel')->andReturnNull();

    $payment = app(MidtransPaymentService::class);
    $snap = $payment->createSnapTransaction($fx['donation'], $fx['donatur'], $fx['campaign']);
    expect($snap['token'])->toBe('snap-token-SYNTHETIC-666');

    $mgmt = app(SubscriptionManagementService::class);
    $pauseResult = $mgmt->pause($fx['subscription']);
    expect($pauseResult['success'])->toBeTrue();
    expect($pauseResult['management_state'])->toBe('paused');
    expect($fx['subscription']->fresh()->management_state)->toBe('paused');
});
