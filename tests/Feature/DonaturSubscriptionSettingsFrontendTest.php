<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }

    Config::set('services.midtrans.server_key', 'SB-Mid-server-test-key');
    Config::set('services.midtrans.client_key', 'SB-Mid-client-test-key');
    Config::set('services.midtrans.signature_secret_key', 'SB-Mid-signing-secret-test-key');
    Config::set('services.midtrans.is_production', false);
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
    Http::preventStrayRequests();
});

function createFrontendTestCampaign(string $title = 'Campaign Bantu Sesama'): Campaign
{
    $penerima = User::factory()->penerima()->create();

    return Campaign::create([
        'user_id' => $penerima->id,
        'judul' => $title,
        'slug' => Str::slug($title) . '-' . Str::random(6),
        'deskripsi' => 'Campaign pengujian frontend E5.2',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 5000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addMonth()->toDateString(),
        'status' => 'aktif',
    ]);
}

test('E5.2 frontend: settings shows authenticated donor subscriptions even with zero recurring cycle donations and excludes foreign donors', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $otherDonatur = User::factory()->donatur()->create();
        $campaignOwn = createFrontendTestCampaign('Campaign Milik Sendiri');
        $campaignForeign = createFrontendTestCampaign('Campaign Milik Orang Lain');

        $ownSub = Subscription::create([
            'subscription_id' => 'sub-fe-own-' . Str::random(6),
            'user_id' => $donatur->id,
            'campaign_id' => $campaignOwn->id,
            'nominal' => 75000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'secret-fe-own-token-999',
            'status' => 'active',
            'management_state' => null,
            'operation_guard_owner' => 'secret-guard-owner-uuid',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                ],
                'internal_diagnostic' => 'should-not-leak',
            ],
        ]);

        // Initial non-recurring donation linked; zero recurring cycle donations (is_recurring=true)
        Donation::create([
            'campaign_id' => $campaignOwn->id,
            'user_id' => $donatur->id,
            'jenis' => 'uang',
            'nominal' => 75000,
            'is_recurring' => false,
            'frequency' => 'bulanan',
            'order_id' => 'INIT-FE-OWN-' . Str::random(6),
            'subscription_id' => $ownSub->subscription_id,
            'status' => 'terverifikasi',
            'payment_status_detail' => 'subscription_activated',
        ]);

        Subscription::create([
            'subscription_id' => 'sub-fe-foreign-' . Str::random(6),
            'user_id' => $otherDonatur->id,
            'campaign_id' => $campaignForeign->id,
            'nominal' => 999000,
            'frequency' => 'mingguan',
            'interval' => 1,
            'interval_unit' => 'week',
            'payment_type' => 'credit_card',
            'token' => 'secret-foreign-token',
            'status' => 'active',
            'management_state' => 'active',
        ]);

        $res = $this->actingAs($donatur)->get(route('donatur.settings'));
        $res->assertOk();

        $html = $res->getContent();
        $res->assertSee('Donasi Rutin');
        $res->assertSee('Campaign Milik Sendiri');
        $res->assertSee('Rp75.000');
        $res->assertSee('Bulanan');
        $res->assertSee('Aktif');
        $res->assertSee('Jadwal terakhir tercatat: 24 Desember 2026, 13:23:36 WIB');
        $res->assertSee('<meta name="csrf-token"', false);

        expect(preg_match('/<script type="application\/json" id="subscriptionInitialData">(.*?)<\/script>/s', $html, $initMatch))->toBe(1);
        $initJson = json_decode($initMatch[1], true);
        expect($initJson['items'])->toHaveCount(1);
        expect($initJson['items'][0]['endpoints']['pause'])->toBe(route('donatur.subscriptions.pause', ['subscription' => $ownSub->id]));
        expect($initJson['items'][0]['endpoints']['cancel'])->toBe(route('donatur.subscriptions.cancel', ['subscription' => $ownSub->id]));

        // Excluded foreign donor, excluded legacy endpoints, and zero secret exposure
        $res->assertDontSee('Campaign Milik Orang Lain');
        $res->assertDontSee('donasi-rutin');
        expect($html)->not->toContain('secret-fe-own-token-999');
        expect($html)->not->toContain('secret-guard-owner-uuid');
        expect($html)->not->toContain('should-not-leak');
        expect($html)->not->toContain('verified_saved_token');
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.2 frontend: renders every required state mapping, restrictions, empty campaign fallback, and safe schedules without exposing sensitive tokens', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    try {
        $donatur = User::factory()->donatur()->create();
        $campaign = createFrontendTestCampaign('Campaign Multi State');

        $base = [
            'user_id' => $donatur->id,
            'campaign_id' => $campaign->id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'secret-state-token',
        ];

        $sPaused = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-paused-' . Str::random(5),
            'frequency' => 'mingguan',
            'interval_unit' => 'week',
            'nominal' => 10000,
            'status' => 'inactive',
            'management_state' => 'paused',
        ]));

        $sCancelled = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-can-' . Str::random(5),
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'next_execution_at' => '2026-12-02 22:24:35',
            'metadata' => ['cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']],
        ]));

        $sReconcile = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-rec-' . Str::random(5),
            'status' => 'active',
            'management_state' => 'reconciliation',
        ]));

        $sHistorical = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-hist-' . Str::random(5),
            'status' => 'active',
            'management_state' => null,
            'next_execution_at' => '2026-12-24 13:23:36',
            'metadata' => [],
        ]));

        $sAmbiguousInactive = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-amb-' . Str::random(5),
            'status' => 'inactive',
            'management_state' => null,
        ]));

        // Unavailable/missing Campaign relation (soft deleted campaign)
        $deletedCampaign = createFrontendTestCampaign('Campaign Dihapus');
        $deletedCampaign->delete();
        $sMissingCamp = Subscription::create(array_merge($base, [
            'subscription_id' => 'sub-st-nocamp-' . Str::random(5),
            'campaign_id' => $deletedCampaign->id,
            'status' => 'active',
            'management_state' => 'active',
        ]));

        $res = $this->actingAs($donatur)->get(route('donatur.settings'));
        $res->assertOk();

        $html = $res->getContent();
        $res->assertSee('Dijeda');
        $res->assertSee('Dibatalkan');
        $res->assertSee('Menunggu Verifikasi');
        $res->assertSee('Status Belum Dipastikan');
        $res->assertSee('Campaign tidak tersedia');
        $res->assertSee('Donasi rutin sedang dijeda.');
        $res->assertSee('Tidak ada penagihan otomatis berikutnya.');
        $res->assertSee('Jadwal menunggu verifikasi.');
        $res->assertSee('Jadwal berikutnya belum terverifikasi.');
        $res->assertSee('Jadwal belum dapat dipastikan.');
        $res->assertSee('Lanjutkan');
        $res->assertSee('Periksa Status');

        // Historical raw date must NEVER appear as confirmed UTC or WIB
        expect($html)->not->toContain('2026-12-24 13:23:36');
        expect($html)->not->toContain('24 Desember 2026, 20:23:36 WIB');
        expect($html)->not->toContain('2026-12-02');
        expect($html)->not->toContain('secret-state-token');

        // Parse embedded initial JSON payload to verify per-item serialization and zero mutation actions for cancelled
        expect(preg_match('/<script type="application\/json" id="subscriptionInitialData">(.*?)<\/script>/s', $html, $m))->toBe(1);
        $embedded = json_decode($m[1], true);
        expect($embedded)->toBeArray();
        $byId = collect($embedded['items'])->keyBy('id');

        expect($byId[$sPaused->id]['frequency_label'])->toBe('Mingguan');
        expect($byId[$sCancelled->id]['next_execution_at'])->toBeNull();
        expect($byId[$sCancelled->id]['schedule']['status'])->toBe('cancelled_no_upcoming_charge');
        expect($byId[$sHistorical->id]['next_execution_at'])->toBeNull();
        expect($byId[$sHistorical->id]['schedule']['status'])->toBe('historical_unverified');
        expect($byId[$sReconcile->id]['management_state'])->toBe('reconciliation');
        expect($byId[$sAmbiguousInactive->id]['management_state'])->toBeNull();
        expect($byId[$sMissingCamp->id]['campaign_title'])->toBe('Campaign tidak tersedia');
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.2 frontend: displays empty subscription state when donor has zero subscriptions and preserves profile + password settings', function () {
    $donatur = User::factory()->donatur()->create([
        'name' => 'Donatur Lama',
        'password' => Hash::make('OldPassword123'),
    ]);

    $getRes = $this->actingAs($donatur)->get(route('donatur.settings'));
    $getRes->assertOk();
    $getRes->assertSee('Belum ada donasi rutin terdaftar.');
    $getRes->assertSee('Edit Profil');
    $getRes->assertSee('Ganti Password');

    $putRes = $this->actingAs($donatur)->put(route('donatur.settings'), [
        'name' => 'Donatur Baru',
        'phone' => '08123456789',
    ]);
    $putRes->assertRedirect(route('donatur.settings'));
    expect($donatur->fresh()->name)->toBe('Donatur Baru');

    $pwRes = $this->actingAs($donatur)->put(route('donatur.settings.password'), [
        'password_lama' => 'OldPassword123',
        'password' => 'NewPassword123',
        'password_confirmation' => 'NewPassword123',
    ]);
    $pwRes->assertRedirect(route('donatur.settings'));
    expect(Hash::check('NewPassword123', $donatur->fresh()->password))->toBeTrue();
});

test('E5.2 frontend: canonical management JSON list exposes campaign_title, canonical local ID endpoints, and no token substrings', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createFrontendTestCampaign('Campaign Endpoint Check');

    $sub = Subscription::create([
        'subscription_id' => 'sub-api-fe-' . Str::random(6),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 50000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'secret-tok-json-api',
        'status' => 'active',
        'management_state' => 'active',
    ]);

    $res = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'));
    $res->assertOk();
    expect($res->getContent())->not->toContain('token');

    $item = $res->json('subscriptions.0');
    expect($item['id'])->toBe($sub->id);
    expect($item['campaign_title'])->toBe('Campaign Endpoint Check');
    expect($item['endpoints']['pause'])->toBe(route('donatur.subscriptions.pause', ['subscription' => $sub->id]));
    expect($item['endpoints']['resume'])->toBe(route('donatur.subscriptions.resume', ['subscription' => $sub->id]));
    expect($item['endpoints']['cancel'])->toBe(route('donatur.subscriptions.cancel', ['subscription' => $sub->id]));
    expect($item['endpoints']['reconcile'])->toBe(route('donatur.subscriptions.reconcile', ['subscription' => $sub->id]));
});

test('E5.2 frontend: donasi-uang checkout view renders safely with F52.5 strict response validation and regular flow intact', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createFrontendTestCampaign('Campaign Checkout Preservation');

    $res = $this->actingAs($donatur)->get(route('donatur.donasi.uang', $campaign->slug));
    $res->assertOk();
    $res->assertSee('Lanjut ke Pembayaran (Midtrans)');
    $res->assertSee('Periksa Riwayat Sebelum Mengulang');
    $res->assertSee("credentials: 'same-origin'", false);
    $res->assertSee('data.success !== true', false);
    $res->assertSee('data.requires_3ds === true', false);
    $res->assertSee('data.subscription_id', false);
});

test('E5.3 F52.4: management API and settings page expose operation_in_progress cleanly, reject occupied guard with 409 conflict_reason, and hide guard UUIDs', function () {
    $donatur = User::factory()->donatur()->create();
    $campaign = createFrontendTestCampaign('Campaign Guard Visibility');
    $guardUuid = Str::uuid()->toString();

    $activeGuarded = Subscription::create([
        'subscription_id' => 'sub-guard-active-' . Str::random(5),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 50000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => 'secret-guard-tok-1',
        'status' => 'active',
        'management_state' => 'active',
        'operation_guard_owner' => $guardUuid,
        'operation_guard_at' => now()->subMinute(),
    ]);

    $expiredGuard = Subscription::create([
        'subscription_id' => 'sub-guard-exp-' . Str::random(5),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 25000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
        'payment_type' => 'credit_card',
        'token' => 'secret-guard-tok-2',
        'status' => 'active',
        'management_state' => 'active',
        'operation_guard_owner' => 'expired-uuid-secret',
        'operation_guard_at' => now()->subMinutes(6),
    ]);

    // 1. Settings page exposes safe boolean without leaking owner UUID or tokens
    $pageRes = $this->actingAs($donatur)->get(route('donatur.settings'));
    $pageRes->assertOk();
    $html = $pageRes->getContent();
    expect($html)->not->toContain($guardUuid);
    expect($html)->not->toContain('expired-uuid-secret');
    expect($html)->not->toContain('secret-guard-tok-1');
    $pageRes->assertSee('Operasi lain sedang diproses di server');

    // 2. Management JSON list reports operation_in_progress respecting 5-min lease
    $listRes = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'));
    $listRes->assertOk();
    expect($listRes->getContent())->not->toContain('token');
    expect($listRes->getContent())->not->toContain($guardUuid);
    $byId = collect($listRes->json('subscriptions'))->keyBy('id');
    expect($byId[$activeGuarded->id]['operation_in_progress'])->toBeTrue();
    expect($byId[$expiredGuard->id]['operation_in_progress'])->toBeFalse();

    // 3. POST while guard is occupied returns HTTP 409 with operation_in_progress=true & conflict_reason=guard_occupied
    Http::fake();
    $conflictRes = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $activeGuarded->id));
    $conflictRes->assertStatus(409)->assertJson([
        'success' => false,
        'operation_in_progress' => true,
        'conflict_reason' => 'guard_occupied',
    ]);
    Http::assertNothingSent();

    // 4. After guard releases, list reflects operation_in_progress=false and pause succeeds safely
    $activeGuarded->update(['operation_guard_owner' => null, 'operation_guard_at' => null]);
    $subId = $activeGuarded->subscription_id;
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
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
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24T13:23:36+07:00'],
            ], 200),
        "https://api.sandbox.midtrans.com/v1/subscriptions/{$subId}/disable" => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    $okRes = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $activeGuarded->id));
    $okRes->assertOk()->assertJson([
        'success' => true,
        'status' => 'inactive',
        'management_state' => 'paused',
        'operation_in_progress' => false,
    ]);
});
