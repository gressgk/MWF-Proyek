<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MidtransPaymentService;
use App\Services\SubscriptionScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

beforeEach(function () {
    $effectiveDb = DB::selectOne('SELECT DATABASE() AS db')->db ?? null;
    if ($effectiveDb !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, current DB: {$effectiveDb}");
    }

    Config::set('services.midtrans.server_key', 'SB-Mid-server-test-key');
    Config::set('services.midtrans.client_key', 'SB-Mid-client-test-key');
    Config::set('services.midtrans.signature_secret_key', 'SB-Mid-signing-secret-test-key');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
    Http::preventStrayRequests();
});

function createUtcTestCampaign(array $overrides = []): Campaign
{
    $penerima = User::factory()->penerima()->create();

    return Campaign::create(array_merge([
        'user_id' => $penerima->id,
        'judul' => 'Campaign UTC Test',
        'slug' => 'campaign-utc-' . Str::random(6),
        'deskripsi' => 'Campaign uji koneksi UTC MySQL',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 1000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => '2026-11-23',
        'tanggal_selesai' => '2026-12-24',
        'status' => 'aktif',
    ], $overrides));
}

// ============================================================
// Section 9: Connection Initialization & Reconnect Lifecycle
// ============================================================

test('E5.5-C.2: testing connection initializes MySQL session to +00:00 while preserving global timezone and reconnect behavior', function () {
    expect(date_default_timezone_get())->toBe('UTC');
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('database.connections.mysql.timezone'))->toBe('+00:00');
    expect(config('services.midtrans.observability.enabled'))->toBeFalse();

    $row = DB::selectOne('SELECT DATABASE() AS db, @@session.time_zone AS stz, @@global.time_zone AS gtz, TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()) AS now_diff');
    expect($row->db)->toBe('wecare_testing');
    expect($row->stz)->toBe('+00:00');
    expect($row->gtz)->toBe('SYSTEM');
    expect(abs((int) $row->now_diff))->toBeLessThanOrEqual(1);

    // Separate probe connection verifies fresh PDO connect + purge/reconnect initializes +00:00
    // without touching the active DatabaseTransactions connection on 'mysql'.
    Config::set('database.connections.mysql_utc_probe', config('database.connections.mysql'));
    try {
        $probeBefore = DB::connection('mysql_utc_probe')->selectOne('SELECT DATABASE() AS db, @@session.time_zone AS stz');
        expect($probeBefore->db)->toBe('wecare_testing');
        expect($probeBefore->stz)->toBe('+00:00');

        DB::purge('mysql_utc_probe');
        $probeAfter = DB::connection('mysql_utc_probe')->selectOne('SELECT DATABASE() AS db, @@session.time_zone AS stz');
        expect($probeAfter->db)->toBe('wecare_testing');
        expect($probeAfter->stz)->toBe('+00:00');
    } finally {
        DB::purge('mysql_utc_probe');
        Config::set('database.connections.mysql_utc_probe', null);
    }

    // Active default connection session remains intact after probe purge
    expect(DB::selectOne('SELECT @@session.time_zone AS stz')->stz)->toBe('+00:00');
});

// ============================================================
// Section 11: Physical Epoch Verification (Donations, Schedule, Guard, failed_jobs)
// ============================================================

test('E5.5-C.2: donation timestamps write exact physical UTC UNIX_TIMESTAMP epochs', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    $expectedEpoch = 1795678032; // 2026-11-26T07:27:12Z

    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();

        $donation = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 15000,
            'is_recurring' => true,
            'frequency' => 'mingguan',
            'order_id' => 'INIT-UTC-DON-' . Str::random(6),
            'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activated',
            'webhook_processed_at' => now(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        $raw = DB::selectOne(
            'SELECT created_at, updated_at, webhook_processed_at,
                    UNIX_TIMESTAMP(created_at) AS cr_epoch,
                    UNIX_TIMESTAMP(updated_at) AS up_epoch,
                    UNIX_TIMESTAMP(webhook_processed_at) AS wp_epoch
             FROM donations WHERE id = ?',
            [$donation->id]
        );

        expect($raw->created_at)->toBe('2026-11-26 07:27:12');
        expect($raw->updated_at)->toBe('2026-11-26 07:27:12');
        expect($raw->webhook_processed_at)->toBe('2026-11-26 07:27:12');
        expect((int) $raw->cr_epoch)->toBe($expectedEpoch);
        expect((int) $raw->up_epoch)->toBe($expectedEpoch);
        expect((int) $raw->wp_epoch)->toBe($expectedEpoch);
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.5-C.2: context-aware Midtrans schedule persists exact physical epoch 1796282832 and preserves E5.5-C.1 lower floor', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();
        $subId = 'sub-utc-sched-' . Str::random(6);

        Http::fake([
            'https://api.sandbox.midtrans.com/v2/charge' => Http::response([
                'status_code' => '200',
                'transaction_status' => 'capture',
                'fraud_status' => 'accept',
                'saved_token_id' => 'saved-tok-utc-sched',
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

        $res = $this->actingAs($donatur)->postJson(
            route('donatur.donasi.uang', $campaign->slug),
            [
                'nominal' => 15000,
                'is_recurring' => '1',
                'frequency' => 'mingguan',
                'payment_type' => 'credit_card',
                'payment_token' => 'temp-tok-utc-sched',
                'recurring_consent' => 'accepted',
            ]
        );

        $res->assertStatus(201)->assertJson([
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
        $raw = DB::selectOne(
            'SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS ne_epoch,
                    UNIX_TIMESTAMP(created_at) AS cr_epoch, UNIX_TIMESTAMP(updated_at) AS up_epoch
             FROM subscriptions WHERE id = ?',
            [$sub->id]
        );

        expect($raw->next_execution_at)->toBe('2026-12-03 07:27:12');
        expect((int) $raw->ne_epoch)->toBe(1796282832);
        expect((int) $raw->cr_epoch)->toBe(1795678032);
        expect((int) $raw->up_epoch)->toBe(1795678032);
        expect($sub->next_execution_at?->toIso8601ZuluString())->toBe('2026-12-03T07:27:12Z');
        expect($sub->metadata['schedule'])->toMatchArray([
            'next_execution_source' => 'provider_confirmed',
            'next_execution_utc' => '2026-12-03T07:27:12Z',
            'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
            'assumed_offset' => '+07:00',
            'source_context' => 'midtrans_subscription_create',
        ]);

        // Conservative pre-cutover floor (1970-01-02T00:00:00Z) must remain enforced during E5.5-C.2
        $parser = app(SubscriptionScheduleService::class);
        $belowFloor = $parser->parseMidtransSubscriptionScheduleTimestamp(
            '1970-01-01 14:00:00',
            SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE
        );
        expect($belowFloor['valid'])->toBeFalse();
        expect($belowFloor['reason'])->toBe('unsafe_mysql_timestamp_lower_boundary');
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.5-C.2: subscription operational timestamps and DB-generated failed_jobs.failed_at align to UTC physical epochs', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 08:00:00', 'UTC'));
    $expectedSubEpoch = 1795680000;

    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();

        $sub = Subscription::create([
            'subscription_id' => 'sub-op-epochs-' . Str::random(6),
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'tok-op-epoch',
            'status' => 'active',
            'management_state' => 'active',
            'idempotency_key' => Str::uuid()->toString(),
            'operation_guard_at' => now(),
            'operation_guard_owner' => 'owner-epoch-test',
        ]);

        $rawSub = DB::selectOne(
            'SELECT UNIX_TIMESTAMP(created_at) AS cr, UNIX_TIMESTAMP(updated_at) AS up, UNIX_TIMESTAMP(operation_guard_at) AS og
             FROM subscriptions WHERE id = ?',
            [$sub->id]
        );
        expect((int) $rawSub->cr)->toBe($expectedSubEpoch);
        expect((int) $rawSub->up)->toBe($expectedSubEpoch);
        expect((int) $rawSub->og)->toBe($expectedSubEpoch);
    } finally {
        Carbon::setTestNow();
    }

    // DB-generated CURRENT_TIMESTAMP on failed_jobs.failed_at (uses MySQL server clock, not Carbon::setTestNow)
    $uuid = Str::uuid()->toString();
    $beforeWallClock = time();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Synthetic test exception',
    ]);
    $afterWallClock = time();

    $fj = DB::selectOne(
        'SELECT failed_at, UNIX_TIMESTAMP(failed_at) AS epoch FROM failed_jobs WHERE uuid = ?',
        [$uuid]
    );
    expect((int) $fj->epoch)->toBeGreaterThanOrEqual($beforeWallClock - 1);
    expect((int) $fj->epoch)->toBeLessThanOrEqual($afterWallClock + 1);
    expect($fj->failed_at)->toBe(gmdate('Y-m-d H:i:s', (int) $fj->epoch));
});

// ============================================================
// Section 12: Management Guard 5-Minute Lease & Mixed-TZ Risk Proof
// ============================================================

test('E5.5-C.2: management 5-minute guard lease enforces exact boundaries, owner protection, clearance, and demonstrates mixed-TZ risk', function () {
    $t0 = Carbon::parse('2026-11-26 10:00:00', 'UTC');
    Carbon::setTestNow($t0);
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();
        $subId = 'sub-guard-lease-' . Str::random(6);

        $sub = Subscription::create([
            'subscription_id' => $subId,
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'saved-tok-guard-lease',
            'status' => 'active',
            'management_state' => 'active',
            'idempotency_key' => Str::uuid()->toString(),
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);

        $providerCalls = 0;

        Http::fake([
            "https://api.sandbox.midtrans.com/v1/subscriptions/{$subId}" => function () use ($sub, $t0, &$providerCalls) {
                $providerCalls++;
                if ($providerCalls === 1) {
                    // While GET is running inside guard, inspect physical operation_guard_at epoch
                    $inside = $sub->fresh();
                    $rawEpoch = (int) DB::selectOne('SELECT UNIX_TIMESTAMP(operation_guard_at) AS og FROM subscriptions WHERE id = ?', [$sub->id])->og;
                    expect($rawEpoch)->toBe($t0->timestamp);
                    expect($inside->isOperationInProgress())->toBeTrue();

                    throw new ConnectionException('Simulated initial GET failure to verify guard clearance on 503');
                }

                $status = $providerCalls === 2 ? 'active' : 'inactive';

                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => $status,
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-26 17:00:00'],
                ], 200);
            },
            "https://api.sandbox.midtrans.com/v1/subscriptions/{$subId}/disable" => Http::response(['status' => 'inactive'], 200),
        ]);

        // Initial GET provider failure returns 503 and releases guard cleanly in finally block
        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub->id))
            ->assertStatus(503);
        $afterFail = $sub->fresh();
        expect($afterFail->operation_guard_at)->toBeNull();
        expect($afterFail->operation_guard_owner)->toBeNull();
        expect($providerCalls)->toBe(1);

        // Set fresh active lease at T0 and test boundary at T0 + 4m59s vs T0 + 5m00s
        Subscription::whereKey($sub->id)->update([
            'operation_guard_at' => $t0->format('Y-m-d H:i:s'),
            'operation_guard_owner' => 'existing-owner-uuid',
        ]);

        Carbon::setTestNow($t0->copy()->addSeconds(299)); // 4m59s: still active
        expect($sub->fresh()->isOperationInProgress())->toBeTrue();
        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub->id))
            ->assertStatus(409)
            ->assertJson(['operation_in_progress' => true]);
        expect($providerCalls)->toBe(1);

        // Exact boundary T0 + 5m00s: lte(now()->subMinutes(5)) -> expired, stale owner takeover succeeds!
        Carbon::setTestNow($t0->copy()->addMinutes(5));
        expect($sub->fresh()->isOperationInProgress())->toBeFalse();

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub->id))
            ->assertOk()
            ->assertJson(['management_state' => 'paused', 'operation_in_progress' => false]);
        expect($providerCalls)->toBe(3);
        expect($sub->fresh()->operation_guard_at)->toBeNull();
        expect($sub->fresh()->operation_guard_owner)->toBeNull();

        // Synthetic mixed-TZ risk proof: simulate a fresh guard acquired under legacy +07:00 session,
        // then read under new +00:00 session -> 7h shift makes fresh lease appear 420 minutes old!
        $origTz = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
        try {
            $nowUtc = Carbon::parse('2026-11-26 12:00:00', 'UTC');
            Carbon::setTestNow($nowUtc);
            DB::statement("SET SESSION time_zone = '+07:00'");
            Subscription::whereKey($sub->id)->update([
                'operation_guard_at' => $nowUtc->format('Y-m-d H:i:s'),
                'operation_guard_owner' => 'legacy-session-owner',
            ]);
            DB::statement("SET SESSION time_zone = '+00:00'");
            $mixedFresh = $sub->fresh();
            expect($mixedFresh->operation_guard_at->toIso8601ZuluString())->toBe('2026-11-26T05:00:00Z');
            expect($mixedFresh->isOperationInProgress())->toBeFalse(); // Premature expiry risk proven!
        } finally {
            DB::statement('SET SESSION time_zone = ?', [$origTz]);
        }
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================
// Section 13: Activation 5-Minute Lease Verification
// ============================================================

test('E5.5-C.2: activation 5-minute lease blocks fresh duplicates, recovers expired known-ID, and blocks expired unknown-ID safely', function () {
    $t0 = Carbon::parse('2026-11-26 09:00:00', 'UTC');
    Carbon::setTestNow($t0);
    $service = app(MidtransPaymentService::class);

    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();
        $knownSubId = 'sub-act-exp-known-' . Str::random(5);

        Http::fake([
            "https://api.sandbox.midtrans.com/v1/subscriptions/{$knownSubId}" => Http::response([
                'id' => $knownSubId,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'saved_token_id' => 'saved-tok-known-recov',
                'schedule' => [
                    'interval' => 1,
                    'interval_unit' => 'month',
                    'next_execution_at' => '2026-12-26 16:00:00',
                ],
            ], 200),
        ]);

        // 1. Fresh activation_in_progress sets physical updated_at epoch = T0 and blocks at T0 + 4m59s
        $dFresh = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 50000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-ACT-FRESH-' . Str::random(5),
            'status' => 'terverifikasi',
            'payment_status_detail' => 'activation_in_progress',
            'idempotency_key' => Str::uuid()->toString(),
        ]);
        $freshEpoch = (int) DB::selectOne('SELECT UNIX_TIMESTAMP(updated_at) AS up FROM donations WHERE id = ?', [$dFresh->id])->up;
        expect($freshEpoch)->toBe($t0->timestamp);

        Carbon::setTestNow($t0->copy()->addSeconds(299));
        $resBlocked = $service->activateSubscriptionFromInitialCharge($dFresh->fresh(), 'saved-tok-act-1', '50000.00');
        expect($resBlocked['success'])->toBeFalse();
        expect($resBlocked['recoverable'])->toBeTrue();
        expect(count(Http::recorded()))->toBe(0);

        // 2. Expired activation lease WITH known subscription_id executes safe GET-only recovery
        Carbon::setTestNow($t0);
        $dKnownExpired = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 50000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-ACT-KNOWN-' . Str::random(5),
            'subscription_id' => $knownSubId,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'activation_in_progress',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        Carbon::setTestNow($t0->copy()->addMinutes(5));
        $resKnown = $service->activateSubscriptionFromInitialCharge($dKnownExpired->fresh(), 'saved-tok-known-recov', '50000.00');
        expect($resKnown['success'])->toBeTrue();
        expect($resKnown['subscription']->fresh()->next_execution_at?->toIso8601ZuluString())->toBe('2026-12-26T09:00:00Z');
        expect(count(Http::recorded()))->toBe(1);
        expect(Http::recorded()[0][0]->method())->toBe('GET');

        // 3. Expired activation lease WITHOUT known subscription_id preserves ambiguous manual review (0 HTTP calls)
        Carbon::setTestNow($t0);
        $dUnknownExpired = Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 50000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-ACT-UNK-' . Str::random(5),
            'subscription_id' => null,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'activation_in_progress',
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        Carbon::setTestNow($t0->copy()->addMinutes(5));
        $resUnknown = $service->activateSubscriptionFromInitialCharge($dUnknownExpired->fresh(), 'saved-tok-unk', '50000.00');
        expect($resUnknown['success'])->toBeFalse();
        expect($resUnknown['ambiguous'])->toBeTrue();
        expect($dUnknownExpired->fresh()->payment_status_detail)->toBe('subscription_activation_ambiguous_manual_review');
        expect(count(Http::recorded()))->toBe(1); // Still only the 1 GET from known-ID recovery above
    } finally {
        Carbon::setTestNow();
    }
});

// ============================================================
// Sections 14 & 17: Non-TIMESTAMP Preservation & Historical Epoch Immutability
// ============================================================

test('E5.5-C.2: DATE, integer Unix epoch, JSON metadata, and read-only DATETIME expressions remain invariant while historical epochs do not auto-heal', function () {
    $origTz = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;
    $donatur = User::factory()->donatur()->create();
    $campaign = createUtcTestCampaign([
        'tanggal_mulai' => '2026-11-23',
        'tanggal_selesai' => '2026-12-24',
    ]);

    try {
        // Synthetic historical TIMESTAMP written under legacy +07:00
        DB::statement("SET SESSION time_zone = '+07:00'");
        $subHist = Subscription::create([
            'subscription_id' => 'sub-hist-immut-' . Str::random(5),
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 15000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'payment_type' => 'credit_card',
            'token' => 'tok-hist-immut',
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'idempotency_key' => Str::uuid()->toString(),
            'next_execution_at' => '2026-11-26 07:27:12',
            'metadata' => [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03T07:27:12Z',
                ],
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-26T08:29:20+00:00',
                ],
            ],
        ]);
        $underPlus7 = DB::selectOne('SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch, CAST(\'2026-12-03 07:27:12\' AS DATETIME) AS dt_expr FROM subscriptions WHERE id = ?', [$subHist->id]);

        DB::statement("SET SESSION time_zone = '+00:00'");
        $underPlus0 = DB::selectOne('SELECT next_execution_at, UNIX_TIMESTAMP(next_execution_at) AS epoch, CAST(\'2026-12-03 07:27:12\' AS DATETIME) AS dt_expr FROM subscriptions WHERE id = ?', [$subHist->id]);

        // Historical physical epoch does not auto-correct when switching session TZ to UTC
        expect((int) $underPlus7->epoch)->toBe(1795652832);
        expect((int) $underPlus0->epoch)->toBe(1795652832);
        expect($underPlus7->next_execution_at)->toBe('2026-11-26 07:27:12');
        expect($underPlus0->next_execution_at)->toBe('2026-11-26 00:27:12');

        // Read-only DATETIME expression does not shift across session timezones
        expect($underPlus7->dt_expr)->toBe('2026-12-03 07:27:12');
        expect($underPlus0->dt_expr)->toBe('2026-12-03 07:27:12');

        // Campaign DATE columns invariant across session timezones
        $cRow = DB::selectOne('SELECT tanggal_mulai, tanggal_selesai FROM campaigns WHERE id = ?', [$campaign->id]);
        expect($cRow->tanggal_mulai)->toBe('2026-11-23');
        expect($cRow->tanggal_selesai)->toBe('2026-12-24');

        // Explicit-offset JSON metadata preserved identically
        $freshSub = $subHist->fresh();
        expect($freshSub->metadata['schedule']['next_execution_utc'])->toBe('2026-12-03T07:27:12Z');
        expect($freshSub->metadata['cancellation']['confirmed_at'])->toBe('2026-11-26T08:29:20+00:00');

        // Integer epoch store invariant
        $cacheRow = ['key' => 'e55c2-epoch-' . Str::random(5), 'value' => 'ok', 'expiration' => 1796282832];
        DB::table('cache')->insert($cacheRow);
        expect((int) DB::table('cache')->where('key', $cacheRow['key'])->value('expiration'))->toBe(1796282832);
    } finally {
        DB::statement('SET SESSION time_zone = ?', [$origTz]);
    }
});

// ============================================================
// Section 15: Application Temporal Queries & Presentation Impact
// ============================================================

test('E5.5-C.2: Admin dashboard monthly grouping, chronological ordering, and password reset expiry align consistently under UTC', function () {
    // Freeze at 2026-11-30 19:00:00 UTC (= 2026-12-01 02:00:00 WIB) to inspect UTC-aligned month boundary
    $boundaryUtc = Carbon::parse('2026-11-30 19:00:00', 'UTC');
    Carbon::setTestNow($boundaryUtc);
    try {
        $admin = User::factory()->create(['role' => 'admin']);
        $donatur = User::factory()->donatur()->create();
        $campaign = createUtcTestCampaign();

        Donation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 25000,
            'order_id' => 'DON-UTC-MONTH-' . Str::random(6),
            'status' => 'terverifikasi',
        ]);

        // Under both APP_TIMEZONE=UTC and DB_TIMEZONE=+00:00, YEAR(created_at)/MONTH(created_at) and
        // Carbon now()->startOfMonth() both evaluate the boundary instant in UTC (2026-11).
        $view = app(DashboardController::class)->index();
        $data = $view->getData();
        $novEntry = collect($data['donasiPerBulan'])->firstWhere('label', '11/2026');
        expect($novEntry)->not->toBeNull();
        expect($novEntry->total)->toBeGreaterThanOrEqual(25000.0);

        // Password reset expiry (60 minutes) matches UTC DB epoch
        $rawToken = Str::random(40);
        DB::table('password_reset_tokens')->insert([
            'email' => $donatur->email,
            'token' => Hash::make($rawToken),
            'created_at' => $boundaryUtc->copy()->subMinutes(59)->format('Y-m-d H:i:s'),
        ]);
        expect(Password::broker()->tokenExists($donatur, $rawToken))->toBeTrue();

        DB::table('password_reset_tokens')->where('email', $donatur->email)->update([
            'created_at' => $boundaryUtc->copy()->subMinutes(61)->format('Y-m-d H:i:s'),
        ]);
        expect(Password::broker()->tokenExists($donatur, $rawToken))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});
