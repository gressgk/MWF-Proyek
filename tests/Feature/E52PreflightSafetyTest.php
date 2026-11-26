<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MidtransPaymentService;
use App\Services\SubscriptionScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }

    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY');
    Config::set('services.midtrans.client_key', 'TEST_ONLY_CLIENT_KEY');
    Config::set('services.midtrans.signature_secret_key', 'TEST_ONLY_SIGNATURE_KEY');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
    Http::preventStrayRequests();
});

function createPreflightCampaign(): Campaign
{
    $penerima = User::factory()->penerima()->create();

    return Campaign::create([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Preflight E5.2',
        'slug' => 'campaign-preflight-' . Str::random(6),
        'deskripsi' => 'Campaign isolasi preflight E5.2',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 10000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addMonth()->toDateString(),
        'status' => 'aktif',
    ]);
}

// ============================================================
// 4.1 MySQL TIMESTAMP & Epoch Behavior Inspection (Isolated Fixture)
// ============================================================

test('E5.2 4.1: reproduces legacy +07:00 physical epoch discrepancy (25200s) and verifies new +00:00 UTC write accuracy (0s) with session restoration', function () {
    $effectiveDb = DB::selectOne('SELECT DATABASE() AS db')->db ?? null;
    expect($effectiveDb)->toBe('wecare_testing');

    $originalTz = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
    expect($originalTz)->toBe('+00:00');

    $donatur = User::factory()->donatur()->create();
    $campaign = createPreflightCampaign();

    try {
        // Provider sends 2026-12-24T13:23:36+07:00 == canonical UTC 2026-12-24T06:23:36Z (epoch 1798093416)
        $parsed = app(SubscriptionScheduleService::class)->parseProviderTimestamp('2026-12-24T13:23:36+07:00');
        expect($parsed['valid'])->toBeTrue();
        $expectedTrueUtcEpoch = 1798093416; // 2026-12-24 06:23:36 UTC
        expect($parsed['utc']->timestamp)->toBe($expectedTrueUtcEpoch);

        // Path A — Legacy +07:00 write (simulates pre-cutover SYSTEM/+07:00 MySQL session)
        DB::statement("SET SESSION time_zone = '+07:00'");
        $subLegacy = Subscription::create([
            'subscription_id' => 'sub-epoch-41-legacy-' . Str::random(5),
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'tok-epoch-legacy',
            'status' => 'active',
            'idempotency_key' => Str::uuid()->toString(),
            'next_execution_at' => $parsed['utc'],
            'metadata' => ['schedule' => app(SubscriptionScheduleService::class)->buildScheduleMetadata($parsed)],
        ]);

        $legacyUnderPlus7 = DB::selectOne(
            'SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch FROM subscriptions WHERE id = ?',
            [$subLegacy->id]
        );

        DB::statement("SET SESSION time_zone = '+00:00'");
        $legacyUnderPlus0 = DB::selectOne(
            'SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch FROM subscriptions WHERE id = ?',
            [$subLegacy->id]
        );

        expect($legacyUnderPlus7->next_execution_at)->toBe('2026-12-24 06:23:36');
        expect((int) $legacyUnderPlus7->epoch)->toBe(1798068216);
        expect((int) $legacyUnderPlus0->epoch)->toBe(1798068216);
        expect($expectedTrueUtcEpoch - (int) $legacyUnderPlus7->epoch)->toBe(25200);
        expect($legacyUnderPlus0->next_execution_at)->toBe('2026-12-23 23:23:36');

        // Path B — New +00:00 UTC write (default testing connection with DB_TIMEZONE=+00:00)
        $subUtc = Subscription::create([
            'subscription_id' => 'sub-epoch-41-utc-' . Str::random(5),
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'tok-epoch-utc',
            'status' => 'active',
            'idempotency_key' => Str::uuid()->toString(),
            'next_execution_at' => $parsed['utc'],
            'metadata' => ['schedule' => app(SubscriptionScheduleService::class)->buildScheduleMetadata($parsed)],
        ]);

        $utcUnderPlus0 = DB::selectOne(
            'SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch FROM subscriptions WHERE id = ?',
            [$subUtc->id]
        );

        DB::statement("SET SESSION time_zone = '+07:00'");
        $utcUnderPlus7 = DB::selectOne(
            'SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch FROM subscriptions WHERE id = ?',
            [$subUtc->id]
        );

        expect($utcUnderPlus0->next_execution_at)->toBe('2026-12-24 06:23:36');
        expect((int) $utcUnderPlus0->epoch)->toBe($expectedTrueUtcEpoch);
        expect($expectedTrueUtcEpoch - (int) $utcUnderPlus0->epoch)->toBe(0);
        expect($utcUnderPlus7->next_execution_at)->toBe('2026-12-24 13:23:36');
        expect((int) $utcUnderPlus7->epoch)->toBe($expectedTrueUtcEpoch);
    } finally {
        DB::statement('SET SESSION time_zone = ?', [$originalTz]);
    }

    $restoredTz = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
    expect($restoredTz)->toBe($originalTz);
});

// ============================================================
// 4.2 Safe Schedule Presentation & Freshness Hardening
// ============================================================

test('E5.2 4.2: serializer never marks billing guaranteed, rejects Eloquent datetime without canonical next_execution_utc, and handles past/stale schedules', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    try {
        $service = app(SubscriptionScheduleService::class);
        $donatur = User::factory()->donatur()->create();
        $campaign = createPreflightCampaign();

        $base = [
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'secret-tok',
        ];

        // 1. Eloquent datetime set, next_execution_source='provider_confirmed', BUT canonical next_execution_utc omitted -> NOT trusted
        $subMissingCanonical = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-no-canon-' . Str::random(5),
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => ['schedule' => ['next_execution_source' => 'provider_confirmed']],
        ]));
        $outMissing = $service->serializeForApi($subMissingCanonical);
        expect($outMissing['next_execution_at'])->toBeNull();
        expect($outMissing['schedule']['status'])->toBe('historical_unverified');
        expect($outMissing['schedule']['is_displayable_upcoming'])->toBeFalse();
        expect($outMissing['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

        // 2. Past canonical timestamp -> not presented as upcoming billing
        $subPast = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-past-' . Str::random(5),
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-11-20 06:00:00',
            'metadata' => ['schedule' => [
                'next_execution_source' => 'provider_confirmed',
                'next_execution_utc' => '2026-11-20T06:00:00Z',
            ]],
        ]));
        $outPast = $service->serializeForApi($subPast);
        expect($outPast['schedule']['status'])->toBe('past_awaiting_refresh');
        expect($outPast['schedule']['is_past'])->toBeTrue();
        expect($outPast['schedule']['is_displayable_upcoming'])->toBeFalse();
        expect($outPast['schedule']['display_wib'])->toBeNull();
        expect($outPast['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

        // 3. Preserved stale schedule -> preserved evidence marked stale_preserved, not freshly displayable
        $subStale = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-stale-' . Str::random(5),
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => ['schedule' => [
                'next_execution_source' => 'provider_confirmed',
                'next_execution_utc' => '2026-12-24T06:23:36Z',
                'freshness' => 'stale_preserved',
            ]],
        ]));
        $outStale = $service->serializeForApi($subStale);
        expect($outStale['schedule']['status'])->toBe('stale_preserved');
        expect($outStale['schedule']['freshness'])->toBe('stale_preserved');
        expect($outStale['schedule']['is_displayable_upcoming'])->toBeFalse();
        expect($outStale['schedule']['display_wib'])->toBeNull();

        // 4. Fresh confirmed future timestamp -> confirmed + WIB + is_upcoming_billing_guaranteed=false
        $subFresh = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-fresh-' . Str::random(5),
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => ['schedule' => [
                'next_execution_source' => 'provider_confirmed',
                'next_execution_utc' => '2026-12-24T06:23:36Z',
                'freshness' => 'fresh',
            ]],
        ]));
        $outFresh = $service->serializeForApi($subFresh);
        expect($outFresh['schedule']['status'])->toBe('confirmed');
        expect($outFresh['schedule']['is_displayable_upcoming'])->toBeTrue();
        expect($outFresh['schedule']['display_wib'])->toBe('24 Desember 2026, 13:23:36 WIB');
        expect($outFresh['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.2 4.2: management operation preserves previous valid schedule while marking freshness stale_preserved when provider omits valid schedule', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createPreflightCampaign();
        $subId = 'sub-stale-mgmt-' . Str::random(6);

        $sub = Subscription::create([
            'subscription_id' => $subId,
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'saved-tok-mgmt-stale',
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => ['schedule' => [
                'next_execution_source' => 'provider_confirmed',
                'next_execution_utc' => '2026-12-24T06:23:36Z',
                'freshness' => 'fresh',
            ]],
        ]);

        Http::fake([
            "https://api.sandbox.midtrans.com/v1/subscriptions/{$subId}" => Http::sequence()
                ->push([
                    'id' => $subId,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24T13:23:36+07:00'],
                ], 200)
                ->push([
                    'id' => $subId,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => 'inactive',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200),
            "https://api.sandbox.midtrans.com/v1/subscriptions/{$subId}/disable" => Http::response([
                'status_message' => 'Subscription is updated.',
            ], 200),
        ]);

        $res = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub->id));
        $res->assertOk();

        $fresh = $sub->fresh();
        expect($fresh->management_state)->toBe('paused');
        expect($fresh->next_execution_at->toIso8601ZuluString())->toBe('2026-12-24T06:23:36Z');
        expect($fresh->metadata['schedule']['freshness'])->toBe('stale_preserved');
        expect($fresh->metadata['schedule']['last_refresh_status'])->toBe('unavailable');
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================
// 4.3 Ambiguous Subscription Creation Retry Policy
// ============================================================

test('E5.2 4.3: ambiguous subscription creation outcomes preserve verified initial donation and block automatic POST retries', function (string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $campaign = createPreflightCampaign();
    $service = app(MidtransPaymentService::class);

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-E52-AMB-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'settled',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    if ($scenario === 'timeout') {
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => function () {
                throw new ConnectionException('Read timeout');
            },
        ]);
    } elseif ($scenario === 'http_500') {
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response(['status_message' => 'Internal error'], 500),
        ]);
    } elseif ($scenario === 'http_200_missing_id') {
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions' => Http::response(['status' => 'active'], 200),
        ]);
    }

    $res1 = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-amb', '100000.00');
    expect($res1['success'])->toBeFalse();
    expect($res1['recoverable'])->toBeFalse();
    expect($res1['ambiguous'])->toBeTrue();

    $donation->refresh();
    expect($donation->status)->toBe('terverifikasi');
    expect($donation->payment_status_detail)->toBe('subscription_activation_ambiguous_manual_review');

    // Subsequent retry call must send 0 HTTP requests
    Http::fake();
    $res2 = $service->activateSubscriptionFromInitialCharge($donation, 'saved-tok-amb', '100000.00');
    expect($res2['success'])->toBeFalse();
    expect($res2['recoverable'])->toBeFalse();
    expect($res2['ambiguous'])->toBeTrue();
    Http::assertNothingSent();
})->with([
    'timeout' => ['timeout'],
    'http_500' => ['http_500'],
    'http_200_missing_id' => ['http_200_missing_id'],
]);

test('E5.2 4.3: verify-activation HTTP endpoint surfaces ambiguous=true and blocks blind POST retries', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createPreflightCampaign();

    $donation = Donation::create([
        'campaign_id' => $campaign->id,
        'user_id' => $donatur->id,
        'jenis' => 'uang',
        'nominal' => 100000,
        'is_recurring' => false,
        'frequency' => 'bulanan',
        'order_id' => 'INIT-VERIFY-AMB-' . Str::random(6),
        'status' => 'terverifikasi',
        'payment_status_detail' => 'subscription_activation_ambiguous_manual_review',
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    Http::fake();

    $res = $this->actingAs($donatur)->postJson(route('donatur.donasi.verify-activation', $donation->order_id));
    $res->assertStatus(422)->assertJson([
        'success' => false,
        'ambiguous' => true,
    ]);
    Http::assertNothingSent();
});
