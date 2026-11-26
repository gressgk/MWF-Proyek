<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }
    Config::set('services.midtrans.server_key', 'TEST_ONLY_SERVER_KEY');
    Config::set('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
    Http::preventStrayRequests();
});

function createManagedSubscription(array $subscriptionOverrides = []): array
{
    $donatur = User::factory()->donatur()->create();
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Subscription Management',
        'slug' => 'campaign-subscription-management-' . Str::random(8),
        'deskripsi' => 'Subscription management test',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 1000000,
        'terkumpul_nominal' => 50000,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addMonth()->toDateString(),
        'status' => 'aktif',
    ]);
    $subscription = Subscription::create(array_merge([
        'subscription_id' => 'sub-management-' . Str::random(10),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 50000,
        'frequency' => 'bulanan',
        'interval' => 1,
        'interval_unit' => 'month',
        'payment_type' => 'credit_card',
        'token' => null,
        'status' => 'active',
        'management_state' => 'active',
        'is_anonim' => false,
        'idempotency_key' => Str::uuid()->toString(),
        'metadata' => ['token_status' => 'missing_saved_token'],
    ], $subscriptionOverrides));
    return compact('donatur', 'penerima', 'campaign', 'subscription');
}

function fakeManagedProvider(Subscription $subscription, string $beforeStatus, string $afterStatus): void
{
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subscription->subscription_id => Http::sequence()
            ->push([
                'id' => $subscription->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $beforeStatus,
                'payment_type' => 'credit_card',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200)
            ->push([
                'id' => $subscription->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $afterStatus,
                'payment_type' => 'credit_card',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200),
    ]);
}

test('owner can pause active subscription and metadata token status is preserved', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
    ]);

    $response = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub));

    $response->assertOk()->assertJson([
        'success' => true,
        'status' => 'inactive',
        'management_state' => 'paused',
    ]);
    expect($sub->fresh()->metadata['token_status'])->toBe('missing_saved_token');
});

test('owner can resume paused subscription and repeated resume is idempotent', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'paused',
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/enable' => Http::response(['status' => 'active'], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active']);

    $sub->refresh();
    $repeatResponse = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub));
    $repeatResponse->assertOk()->assertJson(['success' => true, 'status' => 'active']);
});

test('repeated pause on already paused subscription is idempotent without second disable post', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'paused',
        'token' => 'saved-token-preserve',
        'metadata' => ['token_status' => 'verified_saved_token', 'note' => 'keep'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $beforeMetadata = $sub->fresh()->metadata;
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCalls, $sub) {
            $getCalls++;
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'payment_type' => 'credit_card',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'inactive',
            'management_state' => 'paused',
            'message' => 'Subscription sudah dijeda.',
        ]);

    $fresh = $sub->fresh();
    expect($getCalls)->toBe(1);
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('paused');
    expect($fresh->operation_guard_at)->toBeNull();
    expect($fresh->operation_guard_owner)->toBeNull();
    expect($fresh->token)->toBe('saved-token-preserve');
    expect($fresh->metadata)->toBe($beforeMetadata);
    expect($fresh->metadata['token_status'])->toBe('verified_saved_token');
    expect($fresh->metadata['note'])->toBe('keep');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('owner can cancel subscription and resume is rejected afterward', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'payment_type' => 'credit_card', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response(['status_message' => 'Subscription is updated.'], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});

test('repeated cancel does not send provider mutation repeatedly', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'cancelled',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('guest donatur other mitra and admin cannot manage another subscription', function () {
    ['donatur' => $owner, 'penerima' => $mitra, 'subscription' => $sub] = createManagedSubscription();
    $other = User::factory()->donatur()->create();

    $this->postJson(route('donatur.subscriptions.pause', $sub))->assertRedirect();
    $this->actingAs($other)->postJson(route('donatur.subscriptions.pause', $sub))->assertForbidden();
    $this->actingAs($mitra)->postJson(route('donatur.subscriptions.pause', $sub))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('donatur.subscriptions.pause', $sub))->assertForbidden();
    expect($sub->fresh()->management_state)->toBe('active');
});

test('provider identity and amount mismatch are fail closed without create or charge post', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => 'different-provider-id',
            'amount' => '75000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(422);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && (str_contains($request->url(), '/v1/subscriptions')
            || str_contains($request->url(), '/v2/charge')));
});

test('provider authorization and server errors remain safe', function () {
    foreach ([401, 403, 404, 500] as $status) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response([], $status),
        ]);

        $response = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub));
        $response->assertStatus(in_array($status, [500], true) ? 202 : 503);
        expect($sub->fresh()->management_state)->not->toBe('cancelled');
    }
});

test('provider timeout records reconciliation instead of false success', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
        },
    ]);

    $response = $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub));
    $response->assertStatus(202)->assertJson(['success' => false]);
    expect($sub->fresh()->management_state)->toBe('reconciliation');
});

test('operation guard rejects concurrent duplicate pause', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    $sub->update([
        'operation_guard_at' => now(),
        'operation_guard_owner' => Str::uuid()->toString(),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false]);
    Http::assertNothingSent();
});

test('late inactive webhook after cancel does not reactivate or alter campaign progress', function () {
    Carbon::setTestNow('2026-11-25 16:39:06');
    try {
        ['campaign' => $campaign, 'subscription' => $sub] = createManagedSubscription([
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'token' => 'saved-tok-e518b',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
            ],
        ]);
        $beforeFresh = $sub->fresh();
        $beforeMetadata = $beforeFresh->metadata;
        $beforeUpdatedAt = $beforeFresh->updated_at?->toIso8601String();
        $initialDonations = Donation::count();
        $initialProgress = (int) $campaign->terkumpul_nominal;

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

        $progressMock = \Mockery::mock(\App\Services\CampaignProgressService::class);
        $progressMock->shouldNotReceive('recalculate');
        $this->app->instance(\App\Services\CampaignProgressService::class, $progressMock);

        Carbon::setTestNow('2026-11-25 16:40:00');
        $response = $this->postJson(route('midtrans.webhook.subscription'), [
            'event_name' => 'subscription.inactive',
            'subscription' => ['id' => $sub->subscription_id],
            'transaction' => [
                'status_code' => '200',
                'transaction_status' => 'inactive',
                'order_id' => 'unused-inactive-event',
            ],
        ]);

        $response->assertStatus(200)->assertJson(['message' => 'OK']);
        expect($getCalls)->toBe(1);

        $fresh = $sub->fresh();
        expect($fresh->status)->toBe('inactive');
        expect($fresh->management_state)->toBe('cancelled');
        expect($fresh->metadata)->toBe($beforeMetadata);
        expect($fresh->metadata['cancellation']['confirmed_at'] ?? null)->toBe('2026-11-25T16:39:06+00:00');
        expect($fresh->token)->toBe('saved-tok-e518b');
        expect($fresh->updated_at?->toIso8601String())->toBe($beforeUpdatedAt);
        expect(Donation::count())->toBe($initialDonations);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($initialProgress);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    } finally {
        Carbon::setTestNow();
    }
});

test('cancel timeout blocks resume and reconcile is read only', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'management_state' => 'reconciliation',
        'status' => 'inactive',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'cancel',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertStatus(409);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(202)
        ->assertJson(['management_state' => 'reconciliation']);

    $metadata = $sub->fresh()->metadata;
    expect($metadata['token_status'])->toBe('missing_saved_token');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('pause timeout blocks cancel before reconciliation', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'management_state' => 'reconciliation',
        'status' => 'active',
        'metadata' => [
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
            'token_status' => 'missing_saved_token',
        ],
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(409);
    Http::assertNothingSent();
});

test('provider fractional amount is rejected exactly', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000.49',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(422);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('provider interval 1.5 and invalid interval format are rejected', function () {
    foreach ([1.5, '1.5', 'one'] as $interval) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => $interval, 'interval_unit' => 'month'],
            ], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertStatus(422);
    }
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('ambiguous 500 502 504 mutation enters reconciliation', function () {
    foreach ([500, 502, 504] as $statusCode) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response([], $statusCode),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertStatus(202)
            ->assertJson(['management_state' => 'reconciliation']);
        expect($sub->fresh()->metadata['management_reconciliation'])->toBe('provider_status_pending');
    }
});

test('post success but get mismatch enters reconciliation and no mutation repeats', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => 'different-id', 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(202)
        ->assertJson(['management_state' => 'reconciliation']);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/enable'));
});

test('local paused provider active becomes reconciliation', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'paused',
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(202)
        ->assertJson(['management_state' => 'reconciliation']);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('local active provider inactive becomes reconciliation for local pause', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson(['management_state' => 'paused']);
});

test('strict provider interval rejects 0 2 and minus 1 without mutation', function () {
    foreach ([0, 2, -1] as $interval) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => $interval, 'interval_unit' => 'month'],
            ], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        expect($sub->fresh()->management_state)->toBe('active');
    }
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('strict provider interval accepts integer and string 1', function () {
    foreach ([1, '1'] as $interval) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => $interval, 'interval_unit' => 'month']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => $interval, 'interval_unit' => 'month']], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertOk()
            ->assertJson(['success' => true, 'management_state' => 'paused']);
    }
});

test('resume local active provider inactive persists reconciliation', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertStatus(202)
        ->assertJson([
            'success' => false,
            'management_state' => 'reconciliation',
        ]);

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->metadata['last_management_operation'])->toBe('resume');
    expect($fresh->metadata['management_reconciliation'])->toBe('provider_status_pending');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/enable'));
});

test('repeated cancelled inactive persists reconciliation and reconcile remains pending', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson([
            'success' => false,
            'management_state' => 'reconciliation',
        ]);
    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->metadata['last_management_operation'])->toBe('cancel');
    expect($fresh->metadata['management_reconciliation'])->toBe('provider_status_pending');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(202)
        ->assertJson([
            'success' => false,
            'management_state' => 'reconciliation',
        ]);
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('repeated cancelled get failure persists reconciliation', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->metadata['last_management_operation'])->toBe('cancel');
    expect($fresh->metadata['management_reconciliation'])->toBe('provider_status_pending');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('repeated cancelled with explicit provider cancelled remains idempotent', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => ['token_status' => 'missing_saved_token', 'management_reconciliation' => 'provider_status_pending'],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'cancelled',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);
    expect($sub->fresh()->metadata['token_status'])->toBe('missing_saved_token');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('documented successful cancel stores verified evidence and repeat cancel is idempotent', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect($fresh->metadata['cancellation']['provider_confirmed'])->toBeTrue();
    expect($fresh->metadata['cancellation']['provider_status'])->toBe('inactive');

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);
    $cancelPosts = collect(Http::recorded())->filter(function ($record) use ($sub) {
        [$request] = $record;
        return $request->method() === 'POST'
            && str_contains($request->url(), $sub->subscription_id . '/cancel');
    })->count();
    expect($cancelPosts)->toBe(1);
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
});

test('cancel documented response with HTTP 201 or 202 is not confirmed', function () {
    foreach ([201, 202] as $statusCode) {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
            'metadata' => ['token_status' => 'missing_saved_token'],
        ]);
        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([
                'status_message' => 'Subscription is updated.',
            ], $statusCode),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
            ->assertStatus(202)
            ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
        $fresh = $sub->fresh();
        expect(isset($fresh->metadata['cancellation']))->toBeFalse();
        expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    }
});

test('http 503 cancel persists reconciliation without retry', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([], 503),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson([
            'success' => false,
            'management_state' => 'reconciliation',
        ]);
    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->metadata['last_management_operation'])->toBe('cancel');
    expect($fresh->metadata['management_reconciliation'])->toBe('provider_status_pending');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/enable'));
});

test('malformed cancel response is not confirmed cancellation', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    expect($sub->fresh()->metadata['token_status'])->toBe('missing_saved_token');
    expect(isset($sub->fresh()->metadata['cancellation']))->toBeFalse();
});

test('documented cancel success then get failure reconciles with inactive', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $getCall = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCall, $sub) {
            $getCall++;
            if ($getCall === 2) {
                throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $getCall === 1 ? 'active' : 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    expect($sub->fresh()->metadata['cancellation']['provider_confirmed'])->toBeTrue();

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);
    $fresh = $sub->fresh();
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect(isset($fresh->metadata['cancellation']))->toBeTrue();
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
});

test('cancel timeout without evidence remains reconciliation', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    $fresh = $sub->fresh();
    expect(isset($fresh->metadata['cancellation']))->toBeFalse();
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
});

test('atomic cancellation evidence exists before subsequent get and timeout preserves it', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $getCall = 0;
    $capturedSnapshot = null;

    Carbon::setTestNow('2026-11-24 10:15:00');
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCall, &$capturedSnapshot, $sub) {
            $getCall++;
            if ($getCall === 1) {
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            }

            $fresh = $sub->fresh();
            $capturedSnapshot = [
                'management_state' => $fresh->management_state,
                'provider_confirmed' => $fresh->metadata['cancellation']['provider_confirmed'] ?? null,
                'provider_status' => $fresh->metadata['cancellation']['provider_status'] ?? null,
                'last_management_operation' => $fresh->metadata['last_management_operation'] ?? null,
                'management_reconciliation' => $fresh->metadata['management_reconciliation'] ?? null,
                'confirmed_at' => $fresh->metadata['cancellation']['confirmed_at'] ?? null,
                'token_status' => $fresh->metadata['token_status'] ?? null,
            ];

            throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    expect($getCall)->toBe(2);
    expect($capturedSnapshot)->toBe([
        'management_state' => 'reconciliation',
        'provider_confirmed' => true,
        'provider_status' => 'pending_inactive_verification',
        'last_management_operation' => 'cancel',
        'management_reconciliation' => 'provider_status_pending',
        'confirmed_at' => '2026-11-24T10:15:00+00:00',
        'token_status' => 'missing_saved_token',
    ]);

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->metadata['cancellation']['provider_confirmed'])->toBeTrue();
    expect($fresh->metadata['cancellation']['provider_status'])->toBe('pending_inactive_verification');
    expect($fresh->metadata['last_management_operation'])->toBe('cancel');
    expect($fresh->metadata['management_reconciliation'])->toBe('provider_status_pending');
    expect($fresh->metadata['cancellation']['confirmed_at'])->not->toBeNull();
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');

    $cancelPostsBefore = collect(Http::recorded())->filter(fn ($record) => $record[0]->method() === 'POST'
        && str_contains($record[0]->url(), '/cancel'))->count();
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(409);
    $cancelPostsAfter = collect(Http::recorded())->filter(fn ($record) => $record[0]->method() === 'POST'
        && str_contains($record[0]->url(), '/cancel'))->count();
    expect($cancelPostsAfter)->toBe($cancelPostsBefore);
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);

    Carbon::setTestNow();
});

test('cancellation confirmed timestamp is preserved during later reconciliation', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $getCall = 0;

    Carbon::setTestNow('2026-11-24 10:15:00');
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCall, $sub) {
            $getCall++;
            if ($getCall === 1) {
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            }
            if ($getCall === 2) {
                throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => Http::response([
            'status_message' => 'Subscription is updated.',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['management_state' => 'reconciliation']);
    $confirmedAt = $sub->fresh()->metadata['cancellation']['confirmed_at'];
    expect($confirmedAt)->toBe('2026-11-24T10:15:00+00:00');

    Carbon::setTestNow('2026-11-24 12:45:00');
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);

    $fresh = $sub->fresh();
    expect($fresh->metadata['cancellation']['confirmed_at'])->toBe($confirmedAt);
    expect($fresh->metadata['cancellation']['provider_status'])->toBe('inactive');
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);

    Carbon::setTestNow();
});

test('expired guard with verified cancellation evidence blocks all mutations and reconcile remains read only', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'reconciliation',
        'operation_guard_at' => now()->subMinutes(10),
        'operation_guard_owner' => Str::uuid()->toString(),
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'cancel',
            'management_reconciliation' => 'provider_status_pending',
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'pending_inactive_verification',
                'confirmed_at' => now()->toIso8601String(),
            ],
        ],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'inactive',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))->assertStatus(409);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))->assertStatus(409);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))->assertStatus(409);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'cancelled']);

    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('ambiguous cancel timeout then inactive get remains unconfirmed and no cancel post repeats', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $getCall = 0;
    $cancelPostAttempts = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use (&$getCall, $sub) {
            $getCall++;
            if ($getCall === 1) {
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            }
            if ($getCall === 2) {
                throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => function () use (&$cancelPostAttempts) {
            $cancelPostAttempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Provider timeout');
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    $fresh = $sub->fresh();
    expect($fresh->status)->not->toBe('cancelled');
    expect($fresh->management_state)->toBe('reconciliation');
    expect(isset($fresh->metadata['cancellation']))->toBeFalse();
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect($cancelPostAttempts)->toBe(1);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    $fresh = $sub->fresh();
    expect($fresh->status)->not->toBe('cancelled');
    expect($fresh->management_state)->toBe('reconciliation');
    expect(isset($fresh->metadata['cancellation']))->toBeFalse();
    // Thrown fake responses are not recorded by Http::recorded(), so the
    // invocation counter is the authoritative proof that reconcile did not retry Cancel.
    expect($cancelPostAttempts)->toBe(1);
    expect($getCall)->toBe(3);

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/cancel'));
});

test('recovered subscription with management state null and no local token uses provider subscription id', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'subscription_id' => 'recovered-subscription-e4',
        'management_state' => null,
        'token' => null,
        'status' => 'active',
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/recovered-subscription-e4' => Http::sequence()
            ->push([
                'id' => 'recovered-subscription-e4',
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200)
            ->push([
                'id' => 'recovered-subscription-e4',
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/recovered-subscription-e4/disable' => Http::response([
            'status' => 'inactive',
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);

    $fresh = $sub->fresh();
    expect($fresh->token)->toBeNull();
    expect($fresh->metadata['token_status'])->toBe('missing_saved_token');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/recovered-subscription-e4/disable'));
});

test('no token is returned in management API response', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'token' => str_repeat('sensitive-token-', 20),
    ]);
    $sub->refresh();

    $response = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('token');
});

// ============================================================
// E5.1.8A: RECONCILIATION & STATE CONSISTENCY HARDENING TESTS
// ============================================================

test('e5.1.8a h1: reconcile rejects active guard owned by another operation without provider call', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    $otherOwner = Str::uuid()->toString();
    $guardAt = now()->startOfSecond();
    $sub->update([
        'operation_guard_at' => $guardAt,
        'operation_guard_owner' => $otherOwner,
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    Http::assertNothingSent();
    $fresh = $sub->fresh();
    expect($fresh->operation_guard_owner)->toBe($otherOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($guardAt->toIso8601String());
    expect($fresh->status)->toBe('active');
    expect($fresh->management_state)->toBe('reconciliation');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
});

test('e5.1.8a h2: reconcile acquires guard during provider get and releases it afterward', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'metadata' => ['token_status' => 'missing_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $capturedGuard = null;
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, &$capturedGuard, &$getCalls) {
            $getCalls++;
            $mid = $sub->fresh();
            $capturedGuard = [
                'guard_at_set' => $mid->operation_guard_at !== null,
                'guard_owner' => $mid->operation_guard_owner,
                'status' => $mid->status,
                'management_state' => $mid->management_state,
            ];
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active']);

    expect($getCalls)->toBe(1);
    expect($capturedGuard['guard_at_set'])->toBeTrue();
    expect($capturedGuard['guard_owner'])->not->toBeNull();
    expect($capturedGuard['status'])->toBe('active');
    expect($capturedGuard['management_state'])->toBe('active');

    $fresh = $sub->fresh();
    expect($fresh->operation_guard_at)->toBeNull();
    expect($fresh->operation_guard_owner)->toBeNull();
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('e5.1.8a h3: management operations are blocked with 409 while reconcile holds active operation guard', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    $service = app(\App\Services\SubscriptionManagementService::class);
    $concurrentStatuses = [];
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $service, &$concurrentStatuses, &$getCalls) {
            $getCalls++;
            $concurrentStatuses['pause'] = $service->pause($sub)['http_status'] ?? null;
            $concurrentStatuses['resume'] = $service->resume($sub)['http_status'] ?? null;
            $concurrentStatuses['cancel'] = $service->cancel($sub)['http_status'] ?? null;

            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'paused']);

    expect($getCalls)->toBe(1);
    expect($concurrentStatuses)->toBe([
        'pause' => 409,
        'resume' => 409,
        'cancel' => 409,
    ]);
    $fresh = $sub->fresh();
    expect($fresh->operation_guard_at)->toBeNull();
    expect($fresh->operation_guard_owner)->toBeNull();
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('e5.1.8a h4 h5 h6 h7: consistent non-pending states return 200 without mutating business state or metadata', function () {
    Carbon::setTestNow('2026-11-26 00:10:00');
    try {
        $scenarios = [
            'active' => [
                'attrs' => [
                    'status' => 'active',
                    'management_state' => 'active',
                    'token' => 'saved-token-1',
                    'metadata' => ['token_status' => 'verified_saved_token'],
                ],
                'provider_status' => 'active',
                'expected_status' => 'active',
                'expected_mgmt' => 'active',
            ],
            'paused' => [
                'attrs' => [
                    'status' => 'inactive',
                    'management_state' => 'paused',
                    'token' => 'saved-token-2',
                    'metadata' => ['token_status' => 'verified_saved_token'],
                ],
                'provider_status' => 'inactive',
                'expected_status' => 'inactive',
                'expected_mgmt' => 'paused',
            ],
            'cancelled_with_evidence_inactive' => [
                'attrs' => [
                    'status' => 'inactive',
                    'management_state' => 'cancelled',
                    'token' => 'saved-token-3',
                    'metadata' => [
                        'token_status' => 'verified_saved_token',
                        'cancellation' => [
                            'provider_confirmed' => true,
                            'provider_status' => 'inactive',
                            'confirmed_at' => '2026-11-25T16:39:06+00:00',
                        ],
                    ],
                ],
                'provider_status' => 'inactive',
                'expected_status' => 'inactive',
                'expected_mgmt' => 'cancelled',
            ],
            'cancelled_explicit_provider' => [
                'attrs' => [
                    'status' => 'inactive',
                    'management_state' => 'cancelled',
                    'token' => 'saved-token-4',
                    'metadata' => [
                        'token_status' => 'verified_saved_token',
                        'cancellation' => [
                            'provider_confirmed' => true,
                            'provider_status' => 'inactive',
                            'confirmed_at' => '2026-11-25T16:39:06+00:00',
                        ],
                    ],
                ],
                'provider_status' => 'cancelled',
                'expected_status' => 'inactive',
                'expected_mgmt' => 'cancelled',
            ],
            'legacy_active_null' => [
                'attrs' => [
                    'status' => 'active',
                    'management_state' => null,
                    'token' => null,
                    'metadata' => ['token_status' => 'missing_saved_token'],
                ],
                'provider_status' => 'active',
                'expected_status' => 'active',
                'expected_mgmt' => null,
            ],
            'legacy_inactive_null' => [
                'attrs' => [
                    'status' => 'inactive',
                    'management_state' => null,
                    'token' => null,
                    'metadata' => ['token_status' => 'missing_saved_token'],
                ],
                'provider_status' => 'inactive',
                'expected_status' => 'inactive',
                'expected_mgmt' => null,
            ],
        ];

        foreach ($scenarios as $label => $case) {
            ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription($case['attrs']);
            $beforeMeta = $sub->fresh()->metadata;
            $beforeToken = $sub->fresh()->token;
            $donationCount = Donation::count();
            $progress = (int) $campaign->terkumpul_nominal;

            Http::fake([
                'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => $case['provider_status'],
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200),
            ]);

            $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
                ->assertOk()
                ->assertJson([
                    'success' => true,
                    'status' => $case['expected_status'],
                    'management_state' => $case['expected_mgmt'],
                ]);

            $fresh = $sub->fresh();
            expect($fresh->status)->toBe($case['expected_status']);
            expect($fresh->management_state)->toBe($case['expected_mgmt']);
            expect($fresh->token)->toBe($beforeToken);
            expect($fresh->metadata)->toBe($beforeMeta);
            expect($fresh->operation_guard_at)->toBeNull();
            expect($fresh->operation_guard_owner)->toBeNull();
            if ($label === 'legacy_inactive_null') {
                expect(isset($fresh->metadata['cancellation']))->toBeFalse();
            }
            expect(Donation::count())->toBe($donationCount);
            expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        }
    } finally {
        Carbon::setTestNow();
    }
});

test('e5.1.8a h8 h9 h10 h11: non-pending drift and unverified cancelled fail closed with 409 without mutations', function () {
    $driftCases = [
        'local_active_provider_inactive' => [
            'attrs' => ['status' => 'active', 'management_state' => 'active', 'metadata' => ['token_status' => 'verified_saved_token']],
            'provider_status' => 'inactive',
        ],
        'local_paused_provider_active' => [
            'attrs' => ['status' => 'inactive', 'management_state' => 'paused', 'metadata' => ['token_status' => 'verified_saved_token']],
            'provider_status' => 'active',
        ],
        'local_cancelled_provider_active' => [
            'attrs' => [
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'metadata' => [
                    'token_status' => 'verified_saved_token',
                    'cancellation' => [
                        'provider_confirmed' => true,
                        'provider_status' => 'inactive',
                        'confirmed_at' => '2026-11-25T16:39:06+00:00',
                    ],
                ],
            ],
            'provider_status' => 'active',
        ],
        'local_cancelled_without_evidence_provider_inactive' => [
            'attrs' => [
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'metadata' => ['token_status' => 'missing_saved_token'],
            ],
            'provider_status' => 'inactive',
        ],
        'inconsistent_local_active_with_paused_state' => [
            'attrs' => [
                'status' => 'active',
                'management_state' => 'paused',
                'metadata' => ['token_status' => 'missing_saved_token'],
            ],
            'provider_status' => 'inactive',
        ],
        'legacy_active_provider_inactive' => [
            'attrs' => [
                'status' => 'active',
                'management_state' => null,
                'token' => null,
                'metadata' => ['token_status' => 'missing_saved_token'],
            ],
            'provider_status' => 'inactive',
        ],
    ];

    foreach ($driftCases as $label => $case) {
        ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription($case['attrs']);
        $beforeStatus = $sub->status;
        $beforeMgmt = $sub->management_state;
        $beforeMeta = $sub->fresh()->metadata;
        $donationCount = Donation::count();
        $progress = (int) $campaign->terkumpul_nominal;

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $case['provider_status'],
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'status' => $beforeStatus,
                'management_state' => $beforeMgmt,
            ]);

        $fresh = $sub->fresh();
        expect($fresh->status)->toBe($beforeStatus);
        expect($fresh->management_state)->toBe($beforeMgmt);
        expect($fresh->metadata)->toBe($beforeMeta);
        expect(isset($fresh->metadata['last_management_operation']))->toBeFalse();
        expect($fresh->operation_guard_at)->toBeNull();
        expect($fresh->operation_guard_owner)->toBeNull();
        if ($label === 'local_cancelled_without_evidence_provider_inactive') {
            expect(isset($fresh->metadata['cancellation']))->toBeFalse();
        }
        expect(Donation::count())->toBe($donationCount);
        expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
});

test('e5.1.8a h12: invalid or unsupported provider statuses fail closed and release guard', function () {
    $invalidCases = [
        'missing_status_key' => ['omit_status' => true, 'value' => null],
        'explicit_null_status' => ['omit_status' => false, 'value' => null],
        'empty_string' => ['omit_status' => false, 'value' => ''],
        'whitespace_string' => ['omit_status' => false, 'value' => '   '],
        'array_status' => ['omit_status' => false, 'value' => ['active']],
        'boolean_status' => ['omit_status' => false, 'value' => true],
        'numeric_status' => ['omit_status' => false, 'value' => 123],
        'expired_status' => ['omit_status' => false, 'value' => 'expired'],
        'pending_status' => ['omit_status' => false, 'value' => 'pending'],
        'unknown_status' => ['omit_status' => false, 'value' => 'unknown'],
    ];

    foreach ([
        ['management_state' => 'active', 'expected_http' => 422, 'metadata' => ['token_status' => 'missing_saved_token']],
        ['management_state' => 'reconciliation', 'expected_http' => 202, 'metadata' => ['token_status' => 'missing_saved_token', 'last_management_operation' => 'pause', 'management_reconciliation' => 'provider_status_pending']],
    ] as $mode) {
        foreach ($invalidCases as $case) {
            ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
                'status' => 'active',
                'management_state' => $mode['management_state'],
                'metadata' => $mode['metadata'],
            ]);
            $beforeMeta = $sub->fresh()->metadata;
            $getCalls = 0;

            $payload = [
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ];
            if (!$case['omit_status']) {
                $payload['status'] = $case['value'];
            }

            Http::fake([
                'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($payload, &$getCalls) {
                    $getCalls++;
                    return Http::response($payload, 200);
                },
            ]);

            $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
                ->assertStatus($mode['expected_http'])
                ->assertJson(['success' => false]);

            expect($getCalls)->toBe(1);
            $fresh = $sub->fresh();
            expect($fresh->status)->toBe('active');
            expect($fresh->management_state)->toBe($mode['management_state']);
            expect($fresh->metadata)->toBe($beforeMeta);
            expect($fresh->operation_guard_at)->toBeNull();
            expect($fresh->operation_guard_owner)->toBeNull();
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        }
    }
});

test('e5.1.8a.1 h13 isolated: provider timeout and 4xx or 5xx errors fail safely and release guard', function (string $mode, int $expectedHttp, string $failureType, ?int $errorCode) {
    $metadata = $mode === 'non_pending'
        ? ['token_status' => 'missing_saved_token']
        : ['token_status' => 'missing_saved_token', 'last_management_operation' => 'resume', 'management_reconciliation' => 'provider_status_pending'];

    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => $mode === 'non_pending' ? 'active' : 'reconciliation',
        'token' => 'saved-token-h13',
        'metadata' => $metadata,
    ]);
    $beforeMeta = $sub->fresh()->metadata;
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $callbackHits = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($failureType, $errorCode, &$callbackHits) {
            $callbackHits++;
            if ($failureType === 'connection_exception') {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }
            return Http::response(['error' => 'failed'], $errorCode);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus($expectedHttp)
        ->assertJson(['success' => false]);

    expect($callbackHits)->toBe(1);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->management_state)->toBe($mode === 'non_pending' ? 'active' : 'reconciliation');
    expect($fresh->token)->toBe('saved-token-h13');
    expect($fresh->metadata)->toBe($beforeMeta);
    expect($fresh->operation_guard_at)->toBeNull();
    expect($fresh->operation_guard_owner)->toBeNull();
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'non_pending timeout' => ['non_pending', 503, 'connection_exception', null],
    'non_pending 401' => ['non_pending', 503, 'http_error', 401],
    'non_pending 404' => ['non_pending', 503, 'http_error', 404],
    'non_pending 500' => ['non_pending', 503, 'http_error', 500],
    'non_pending 503' => ['non_pending', 503, 'http_error', 503],
    'pending timeout' => ['pending', 202, 'connection_exception', null],
    'pending 401' => ['pending', 202, 'http_error', 401],
    'pending 404' => ['pending', 202, 'http_error', 404],
    'pending 500' => ['pending', 202, 'http_error', 500],
    'pending 503' => ['pending', 202, 'http_error', 503],
]);

test('e5.1.8a h14 h15 h16 h17 h19: pending pause resume and cancel reconciliation honor evidence and financial integrity', function () {
    // H14: Pending Pause -> paused
    ['campaign' => $cPause, 'donatur' => $dPause, 'subscription' => $subPause] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'token' => 'tok-pause',
        'metadata' => ['token_status' => 'verified_saved_token', 'last_management_operation' => 'pause', 'management_reconciliation' => 'provider_status_pending'],
    ]);
    $donCount = Donation::count();
    $progPause = (int) $cPause->terkumpul_nominal;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subPause->subscription_id => Http::response([
            'id' => $subPause->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $this->actingAs($dPause)->postJson(route('donatur.subscriptions.reconcile', $subPause))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'paused']);
    $freshPause = $subPause->fresh();
    expect(isset($freshPause->metadata['last_management_operation']))->toBeFalse();
    expect($freshPause->token)->toBe('tok-pause');
    expect($freshPause->metadata['token_status'])->toBe('verified_saved_token');
    expect($freshPause->operation_guard_at)->toBeNull();
    expect((int) $cPause->fresh()->terkumpul_nominal)->toBe($progPause);

    // H15: Pending Resume -> active
    ['campaign' => $cRes, 'donatur' => $dRes, 'subscription' => $subRes] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'reconciliation',
        'token' => 'tok-resume',
        'metadata' => ['token_status' => 'verified_saved_token', 'last_management_operation' => 'resume', 'management_reconciliation' => 'provider_status_pending'],
    ]);
    $progRes = (int) $cRes->terkumpul_nominal;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subRes->subscription_id => Http::response([
            'id' => $subRes->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $this->actingAs($dRes)->postJson(route('donatur.subscriptions.reconcile', $subRes))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active']);
    $freshRes = $subRes->fresh();
    expect(isset($freshRes->metadata['last_management_operation']))->toBeFalse();
    expect($freshRes->token)->toBe('tok-resume');
    expect($freshRes->operation_guard_at)->toBeNull();
    expect((int) $cRes->fresh()->terkumpul_nominal)->toBe($progRes);

    // H16: Pending Cancel with verified evidence -> cancelled preserving exact confirmed_at
    ['donatur' => $dCanOk, 'subscription' => $subCanOk] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'cancel',
            'management_reconciliation' => 'provider_status_pending',
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'pending_inactive_verification',
                'confirmed_at' => '2026-11-25T16:39:06+00:00',
            ],
        ],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subCanOk->subscription_id => Http::response([
            'id' => $subCanOk->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $this->actingAs($dCanOk)->postJson(route('donatur.subscriptions.reconcile', $subCanOk))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'cancelled']);
    $freshCanOk = $subCanOk->fresh();
    expect($freshCanOk->metadata['cancellation']['confirmed_at'])->toBe('2026-11-25T16:39:06+00:00');
    expect($freshCanOk->metadata['cancellation']['provider_status'])->toBe('inactive');
    expect(isset($freshCanOk->metadata['last_management_operation']))->toBeFalse();
    expect($freshCanOk->operation_guard_at)->toBeNull();

    // H17: Pending Cancel without verified evidence -> remains 202 reconciliation
    ['donatur' => $dCanAmb, 'subscription' => $subCanAmb] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'cancel',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subCanAmb->subscription_id => Http::response([
            'id' => $subCanAmb->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $this->actingAs($dCanAmb)->postJson(route('donatur.subscriptions.reconcile', $subCanAmb))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);
    $freshCanAmb = $subCanAmb->fresh();
    expect($freshCanAmb->management_state)->toBe('reconciliation');
    expect(isset($freshCanAmb->metadata['cancellation']))->toBeFalse();
    expect($freshCanAmb->operation_guard_at)->toBeNull();
    expect(Donation::count())->toBe($donCount);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('e5.1.8a h18: expired guard takeover succeeds and stale owner finalization is aborted without releasing new guard', function () {
    // 1. Expired guard takeover by reconcile succeeds and releases its own guard
    ['donatur' => $donatur, 'subscription' => $subExpired] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'operation_guard_at' => now()->subMinutes(10),
        'operation_guard_owner' => Str::uuid()->toString(),
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subExpired->subscription_id => Http::response([
            'id' => $subExpired->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $subExpired))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'paused']);
    expect($subExpired->fresh()->operation_guard_owner)->toBeNull();
    expect($subExpired->fresh()->operation_guard_at)->toBeNull();

    // 2. Stale-owner finalization protection: guard taken over mid-GET aborts DB write and preserves new owner's guard
    ['donatur' => $donatur2, 'subscription' => $subStale] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'missing_saved_token',
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $subStale->subscription_id => function () use ($subStale, $newOwner, $newGuardAt) {
            Subscription::whereKey($subStale->id)->update([
                'operation_guard_at' => $newGuardAt,
                'operation_guard_owner' => $newOwner,
            ]);
            return Http::response([
                'id' => $subStale->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur2)->postJson(route('donatur.subscriptions.reconcile', $subStale))
        ->assertStatus(409)
        ->assertJson(['success' => false, 'status' => 'active', 'management_state' => 'reconciliation']);

    $freshStale = $subStale->fresh();
    expect($freshStale->status)->toBe('active');
    expect($freshStale->management_state)->toBe('reconciliation');
    expect($freshStale->operation_guard_owner)->toBe($newOwner);
    expect($freshStale->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
});

test('e5.1.8a.1 e1: non-pending reconcile returns 409 when guard is taken over and local state changes mid-get', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'token' => 'saved-token-e1',
        'next_execution_at' => '2026-12-24 13:23:36',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $newOwner, $newGuardAt, &$getCalls) {
            $getCalls++;
            // Controlled simulation (not true parallel execution): new owner takes over guard and cancels subscription mid-GET.
            Subscription::whereKey($sub->id)->update([
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'operation_guard_at' => $newGuardAt,
                'operation_guard_owner' => $newOwner,
                'metadata' => [
                    'token_status' => 'verified_saved_token',
                    'cancellation' => [
                        'provider_confirmed' => true,
                        'provider_status' => 'inactive',
                        'confirmed_at' => '2026-11-25T16:39:06+00:00',
                    ],
                ],
            ]);

            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'cancelled',
        ]);

    expect($getCalls)->toBe(1);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->metadata['cancellation']['provider_confirmed'])->toBeTrue();
    expect($fresh->metadata['cancellation']['provider_status'])->toBe('inactive');
    expect($fresh->metadata['cancellation']['confirmed_at'])->toBe('2026-11-25T16:39:06+00:00');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->token)->toBe('saved-token-e1');
    expect($fresh->next_execution_at?->format('Y-m-d H:i:s'))->toBe('2026-12-24 13:23:36');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('e5.1.8a.1 e2: non-pending reconcile returns 409 when new owner already completed and released guard', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'token' => 'saved-token-e2',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, &$getCalls) {
            $getCalls++;
            // Simulate new owner finished pause and released guard back to null before stale reconcile processed GET.
            Subscription::whereKey($sub->id)->update([
                'status' => 'inactive',
                'management_state' => 'paused',
                'operation_guard_at' => null,
                'operation_guard_owner' => null,
            ]);

            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);

    expect($getCalls)->toBe(1);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('paused');
    expect($fresh->operation_guard_at)->toBeNull();
    expect($fresh->operation_guard_owner)->toBeNull();
    expect($fresh->token)->toBe('saved-token-e2');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

test('e5.1.8a.1 e5: provider failure or timeout with ownership loss fails closed with 409 and preserves new guard', function (string $mode, string $failureType) {
    $metadata = $mode === 'non_pending'
        ? ['token_status' => 'verified_saved_token']
        : ['token_status' => 'verified_saved_token', 'last_management_operation' => 'pause', 'management_reconciliation' => 'provider_status_pending'];

    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => $mode === 'non_pending' ? 'active' : 'reconciliation',
        'token' => 'saved-token-e5',
        'metadata' => $metadata,
    ]);
    $donationCount = Donation::count();
    $progress = (int) $campaign->terkumpul_nominal;
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $callbackHits = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $newOwner, $newGuardAt, $failureType, &$callbackHits) {
            $callbackHits++;
            Subscription::whereKey($sub->id)->update([
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'operation_guard_at' => $newGuardAt,
                'operation_guard_owner' => $newOwner,
                'metadata' => [
                    'token_status' => 'verified_saved_token',
                    'cancellation' => [
                        'provider_confirmed' => true,
                        'provider_status' => 'inactive',
                        'confirmed_at' => '2026-11-25T16:39:06+00:00',
                    ],
                ],
            ]);

            if ($failureType === 'connection_exception') {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }
            return Http::response(['error' => 'upstream'], 500);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'cancelled',
        ]);

    expect($callbackHits)->toBe(1);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->metadata['cancellation']['confirmed_at'])->toBe('2026-11-25T16:39:06+00:00');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->token)->toBe('saved-token-e5');
    expect(Donation::count())->toBe($donationCount);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($progress);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'non_pending timeout' => ['non_pending', 'connection_exception'],
    'non_pending 500' => ['non_pending', 'http_500'],
    'pending timeout' => ['pending', 'connection_exception'],
    'pending 500' => ['pending', 'http_500'],
]);

test('e5.1.8c management: invalid provider amount or fractional local nominal fails closed without provider mutations across pause, resume, cancel, and reconcile', function (string $action, string $scenario) {
    $donatur = User::factory()->donatur()->create();
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id' => $penerima->id,
        'judul' => 'Campaign Mgmt Amount Test',
        'slug' => 'campaign-mgmt-amt-' . rand(1000, 9999),
        'deskripsi' => 'Deskripsi',
        'tipe_bantuan' => 'uang',
        'target_nominal' => 1000000,
        'terkumpul_nominal' => 0,
        'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(),
        'tanggal_selesai' => now()->addDays(30)->toDateString(),
        'status' => 'aktif',
    ]);

    $isPendingReconcile = ($action === 'reconcile_pending');
    $sub = Subscription::create([
        'subscription_id' => 'sub-e518c-mgmt-' . Str::random(6),
        'user_id' => $donatur->id,
        'campaign_id' => $campaign->id,
        'nominal' => 10000,
        'frequency' => 'mingguan',
        'interval' => 1,
        'interval_unit' => 'week',
        'payment_type' => 'credit_card',
        'token' => 'saved-tok-mgmt-e518c',
        'status' => $action === 'resume' ? 'inactive' : 'active',
        'management_state' => $isPendingReconcile ? 'reconciliation' : ($action === 'resume' ? 'paused' : 'active'),
        'metadata' => $isPendingReconcile
            ? ['last_management_operation' => 'pause', 'management_reconciliation' => 'provider_status_pending']
            : ['token_status' => 'verified_saved_token'],
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    if ($scenario === 'fractional_local') {
        DB::table('subscriptions')->where('id', $sub->id)->update(['nominal' => '10000.99']);
        $sub->refresh();
    }

    $providerAmount = $scenario === 'invalid_provider_sci' ? '1e4' : ($scenario === 'invalid_provider_ws' ? ' 10000 ' : '10000');

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => $providerAmount,
            'currency' => 'IDR',
            'status' => $action === 'resume' ? 'inactive' : 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'week'],
        ], 200),
    ]);

    $routeName = match ($action) {
        'pause' => 'donatur.subscriptions.pause',
        'resume' => 'donatur.subscriptions.resume',
        'cancel' => 'donatur.subscriptions.cancel',
        'reconcile_non_pending', 'reconcile_pending' => 'donatur.subscriptions.reconcile',
    };

    $expectedHttp = $isPendingReconcile ? 202 : 422;
    $this->actingAs($donatur)->postJson(route($routeName, $sub))
        ->assertStatus($expectedHttp)
        ->assertJson(['success' => false]);

    $fresh = $sub->fresh();
    expect($fresh->operation_guard_owner)->toBeNull();
    expect($fresh->operation_guard_at)->toBeNull();
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'pause invalid provider sci' => ['pause', 'invalid_provider_sci'],
    'resume invalid provider ws' => ['resume', 'invalid_provider_ws'],
    'cancel invalid provider sci' => ['cancel', 'invalid_provider_sci'],
    'reconcile non-pending invalid provider' => ['reconcile_non_pending', 'invalid_provider_sci'],
    'reconcile pending invalid provider' => ['reconcile_pending', 'invalid_provider_sci'],
    'pause fractional local nominal' => ['pause', 'fractional_local'],
    'reconcile pending fractional local nominal' => ['reconcile_pending', 'fractional_local'],
]);

// ============================================================
// E5.1.8D: MANAGEMENT GUARD OWNERSHIP HARDENING TESTS (GROUPS A-H)
// ============================================================

// Group A — Guard Ownership Lost Before Mutation
test('E5.1.8D: group A pause resume cancel ownership lost during initial GET returns 409 without mutation POST or guard release', function (string $action) {
    $isResume = ($action === 'resume');
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => $isResume ? 'inactive' : 'active',
        'management_state' => $isResume ? 'paused' : 'active',
        'token' => 'saved-tok-e518d-a',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $beforeDonations = Donation::count();
    $beforeProgress = (int) $campaign->terkumpul_nominal;
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $newOwner, $newGuardAt, $isResume, &$getCalls) {
            $getCalls++;
            // Controlled simulation (not true parallel execution): newer owner acquires guard during initial GET.
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'metadata' => ['token_status' => 'verified_saved_token', 'newer_owner_marker' => 'keep'],
            ]);

            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $isResume ? 'inactive' : 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.' . $action, $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    expect($getCalls)->toBe(1);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe($isResume ? 'inactive' : 'active');
    expect($fresh->management_state)->toBe($isResume ? 'paused' : 'active');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->metadata['newer_owner_marker'] ?? null)->toBe('keep');
    expect(isset($fresh->metadata['cancellation']))->toBeFalse();
    expect(Donation::count())->toBe($beforeDonations);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($beforeProgress);
    Http::assertNotSent(fn ($req) => $req->method() === 'POST');
})->with([
    'pause' => ['pause'],
    'resume' => ['resume'],
    'cancel' => ['cancel'],
]);

// Group B — Guard Ownership Lost During Provider Mutation
test('E5.1.8D: group B pause and resume ownership lost during provider mutation returns 409 without post-mutation GET or local write', function (string $action) {
    $isResume = ($action === 'resume');
    $endpoint = $isResume ? '/enable' : '/disable';
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => $isResume ? 'inactive' : 'active',
        'management_state' => $isResume ? 'paused' : 'active',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;
    $postCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $isResume, &$getCalls) {
            $getCalls++;
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $isResume ? 'inactive' : 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . $endpoint => function () use ($sub, $newOwner, $newGuardAt, &$postCalls) {
            $postCalls++;
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'metadata' => [
                    'token_status' => 'verified_saved_token',
                    'cancellation' => [
                        'provider_confirmed' => true,
                        'provider_status' => 'inactive',
                        'confirmed_at' => '2026-11-26T01:00:00+00:00',
                    ],
                ],
            ]);
            return Http::response(['status' => $endpoint === '/enable' ? 'active' : 'inactive'], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.' . $action, $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'cancelled',
        ]);

    expect($postCalls)->toBe(1);
    expect($getCalls)->toBe(1); // Must NOT perform post-mutation GET
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe(50000);
})->with([
    'pause' => ['pause'],
    'resume' => ['resume'],
]);

test('E5.1.8D: group B cancel ownership lost during cancel POST returns 409 without writing cancel ack evidence or post-mutation GET', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, &$getCalls) {
            $getCalls++;
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => function () use ($sub, $newOwner, $newGuardAt) {
            // Simulate newer owner taking over during /cancel HTTP call and putting sub into paused state
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'status' => 'inactive',
                'management_state' => 'paused',
                'metadata' => ['token_status' => 'verified_saved_token', 'newer_owner' => true],
            ]);
            return Http::response(['status_message' => 'Subscription is updated.'], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);

    expect($getCalls)->toBe(1); // No post-mutation GET
    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('paused');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->metadata['newer_owner'] ?? null)->toBeTrue();
    expect(isset($fresh->metadata['cancellation']))->toBeFalse();
});

// Group C — Guard Ownership Lost During Post-Mutation Verification GET
test('E5.1.8D: group C pause resume and cancel ownership lost during post-mutation GET returns 409 and preserves newer owner state and guard', function (string $action) {
    $isResume = ($action === 'resume');
    $endpoint = $action === 'pause' ? '/disable' : ($action === 'resume' ? '/enable' : '/cancel');
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => $isResume ? 'inactive' : 'active',
        'management_state' => $isResume ? 'paused' : 'active',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $isResume, $newOwner, $newGuardAt, &$getCalls) {
            $getCalls++;
            if ($getCalls === 1) {
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => $isResume ? 'inactive' : 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            }

            // Simulate guard transfer to newer owner during 2nd GET
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'status' => 'active',
                'management_state' => 'active',
                'metadata' => ['token_status' => 'verified_saved_token', 'newer_phase' => 'post_get_takeover'],
            ]);

            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $isResume ? 'active' : 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . $endpoint => Http::response(
            $action === 'cancel' ? ['status_message' => 'Subscription is updated.'] : ['status' => 'ok'],
            200
        ),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.' . $action, $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'active',
            'management_state' => 'active',
        ]);

    expect($getCalls)->toBe(2);
    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('active');
    expect($fresh->management_state)->toBe('active');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->metadata['newer_phase'] ?? null)->toBe('post_get_takeover');
})->with([
    'pause' => ['pause'],
    'resume' => ['resume'],
    'cancel' => ['cancel'],
]);

// Group D — Provider Exceptions Under Retained vs Transferred Ownership
test('E5.1.8D: group D pause mutation timeout with retained ownership writes reconciliation and releases guard', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => 'IDR',
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Mutation timeout');
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(202)
        ->assertJson(['success' => false, 'management_state' => 'reconciliation']);

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('reconciliation');
    expect($fresh->operation_guard_owner)->toBeNull();
    expect($fresh->operation_guard_at)->toBeNull();
});

test('E5.1.8D: group D provider exceptions during initial GET mutation or post-mutation GET with transferred ownership return 409 without overwriting state or releasing new guard', function (string $stage) {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();
    $getCalls = 0;

    $transferGuard = function () use ($sub, $newOwner, $newGuardAt) {
        Subscription::whereKey($sub->id)->update([
            'operation_guard_owner' => $newOwner,
            'operation_guard_at' => $newGuardAt,
            'status' => 'inactive',
            'management_state' => 'paused',
            'metadata' => ['token_status' => 'verified_saved_token', 'preserved_owner' => $newOwner],
        ]);
    };

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $stage, $transferGuard, &$getCalls) {
            $getCalls++;
            if ($stage === 'initial_get_timeout') {
                $transferGuard();
                throw new \Illuminate\Http\Client\ConnectionException('Initial GET timeout');
            }
            if ($getCalls === 2 && $stage === 'post_get_timeout') {
                $transferGuard();
                throw new \Illuminate\Http\Client\ConnectionException('Post GET timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'active',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () use ($stage, $transferGuard) {
            if ($stage === 'mutation_timeout') {
                $transferGuard();
                throw new \Illuminate\Http\Client\ConnectionException('Disable timeout');
            }
            if ($stage === 'mutation_503') {
                $transferGuard();
                return Http::response(['status_message' => 'Unavailable'], 503);
            }
            return Http::response(['status' => 'inactive'], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(409)
        ->assertJson([
            'success' => false,
            'status' => 'inactive',
            'management_state' => 'paused',
        ]);

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('inactive');
    expect($fresh->management_state)->toBe('paused');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->metadata['preserved_owner'] ?? null)->toBe($newOwner);
    expect(isset($fresh->metadata['management_reconciliation']))->toBeFalse();
})->with([
    'initial GET timeout' => ['initial_get_timeout'],
    'mutation timeout' => ['mutation_timeout'],
    'mutation HTTP 503' => ['mutation_503'],
    'post-mutation GET timeout' => ['post_get_timeout'],
]);

// Group E — Repeated Cancel & verifyCancelledNoOp() Ownership Loss
test('E5.1.8D: group E repeated cancel ownership lost during verifyCancelledNoOp GET returns 409 without overwriting state or releasing new guard', function (string $scenario) {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => [
            'token_status' => 'verified_saved_token',
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'inactive',
                'confirmed_at' => '2026-11-25T16:39:06+00:00',
            ],
        ],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $scenario, $newOwner, $newGuardAt) {
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'metadata' => [
                    'token_status' => 'verified_saved_token',
                    'cancellation' => [
                        'provider_confirmed' => true,
                        'provider_status' => 'inactive',
                        'confirmed_at' => '2026-11-25T16:39:06+00:00',
                    ],
                    'newer_cancel_marker' => 'intact',
                ],
            ]);

            if ($scenario === 'timeout') {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => $scenario === 'explicit_cancelled' ? 'cancelled' : 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false]);

    $fresh = $sub->fresh();
    expect($fresh->management_state)->toBe('cancelled');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->metadata['newer_cancel_marker'] ?? null)->toBe('intact');
    expect(isset($fresh->metadata['management_reconciliation']))->toBeFalse();
    Http::assertNotSent(fn ($req) => $req->method() === 'POST');
})->with([
    'explicit cancelled' => ['explicit_cancelled'],
    'inactive' => ['inactive'],
    'GET timeout' => ['timeout'],
]);

// Group F — Atomic Metadata Protection & Lease Behavior
test('E5.1.8D: group F fresh occupied guard rejects pause resume cancel and reconcile with 409 and zero outbound calls', function (string $action) {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'operation_guard_at' => now()->subMinutes(2),
        'operation_guard_owner' => Str::uuid()->toString(),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.' . $action, $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false]);
    Http::assertNothingSent();
})->with([
    'pause' => ['pause'],
    'resume' => ['resume'],
    'cancel' => ['cancel'],
    'reconcile' => ['reconcile'],
]);

test('E5.1.8D: group F expired 5-minute lease allows takeover and stale owner cannot release new guard', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'operation_guard_at' => now()->subMinutes(5)->subSecond(),
        'operation_guard_owner' => 'stale-expired-owner',
    ]);
    $service = app(\App\Services\SubscriptionManagementService::class);
    $observedNewOwner = null;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () use ($sub, &$observedNewOwner) {
            $observedNewOwner = $sub->fresh()->operation_guard_owner;
            return Http::response(['status' => 'inactive'], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'management_state' => 'paused']);

    expect($observedNewOwner)->not->toBeNull();
    expect($observedNewOwner)->not->toBe('stale-expired-owner');
    expect($sub->fresh()->operation_guard_owner)->toBeNull();

    // Also verify calling releaseGuard with a non-matching owner leaves a currently active guard intact
    $activeOwner = Str::uuid()->toString();
    $sub->update(['operation_guard_owner' => $activeOwner, 'operation_guard_at' => now()]);
    $refRelease = new ReflectionMethod($service, 'releaseGuard');
    $refRelease->setAccessible(true);
    $refRelease->invoke($service, $sub, 'stale-expired-owner');
    expect($sub->fresh()->operation_guard_owner)->toBe($activeOwner);
});

test('E5.1.8D: group F atomic metadata built from locked DB row preserves concurrent unrelated metadata updates and existing confirmed_at', function () {
    Carbon::setTestNow('2026-11-26 02:30:00');
    try {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
            'status' => 'active',
            'management_state' => 'active',
            'metadata' => ['token_status' => 'verified_saved_token'],
        ]);
        $getCalls = 0;

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, &$getCalls) {
                $getCalls++;
                if ($getCalls === 2) {
                    // Add an unrelated metadata key under the same guard before final write
                    $fresh = $sub->fresh();
                    $meta = $fresh->metadata ?? [];
                    $meta['custom_audit_marker'] = 'keep-me';
                    Subscription::whereKey($sub->id)->update(['metadata' => json_encode($meta)]);
                }
                return Http::response([
                    'id' => $sub->subscription_id,
                    'amount' => '50000',
                    'currency' => 'IDR',
                    'status' => $getCalls === 1 ? 'active' : 'inactive',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            },
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertOk()
            ->assertJson(['success' => true, 'management_state' => 'paused']);

        $fresh = $sub->fresh();
        expect($fresh->metadata['token_status'])->toBe('verified_saved_token');
        expect($fresh->metadata['custom_audit_marker'] ?? null)->toBe('keep-me');
    } finally {
        Carbon::setTestNow();
    }
});

// Group G — Full Idempotency Regression
test('E5.1.8D: group G pause resume repeated resume executes exact mutation counts returns Subscription sudah aktif and preserves financial invariants', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'token' => 'saved-tok-e518d-g',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $beforeDonations = Donation::count();
    $beforeProgress = (int) $campaign->terkumpul_nominal;
    $disablePosts = 0;
    $enablePosts = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () use (&$disablePosts) {
            $disablePosts++;
            return Http::response(['status' => 'inactive'], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/enable' => function () use (&$enablePosts) {
            $enablePosts++;
            return Http::response(['status' => 'active'], 200);
        },
    ]);

    // 1. Pause
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'paused']);

    // 2. Resume
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active']);

    // 3. Repeated Resume -> must return 'Subscription sudah aktif.' with zero additional enable POST
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertOk()
        ->assertJson([
            'success' => true,
            'status' => 'active',
            'management_state' => 'active',
            'message' => 'Subscription sudah aktif.',
        ]);

    expect($disablePosts)->toBe(1);
    expect($enablePosts)->toBe(1);
    expect(Donation::count())->toBe($beforeDonations);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($beforeProgress);
    expect($sub->fresh()->operation_guard_owner)->toBeNull();
});

test('E5.1.8D: group G pause or resume after confirmed cancel returns 422 with zero outbound calls', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
        'metadata' => [
            'token_status' => 'verified_saved_token',
            'cancellation' => [
                'provider_confirmed' => true,
                'provider_status' => 'inactive',
                'confirmed_at' => '2026-11-25T16:39:06+00:00',
            ],
        ],
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))->assertStatus(422);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))->assertStatus(422);
    Http::assertNothingSent();
});

// Group H — Reconcile Ownership Loss Regression
test('E5.1.8D: group H pending reconcile ownership lost on GET response or exception returns 409 without mutating newer owner state or guard', function (bool $throwOnGet) {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'reconciliation',
        'metadata' => [
            'token_status' => 'verified_saved_token',
            'last_management_operation' => 'pause',
            'management_reconciliation' => 'provider_status_pending',
        ],
    ]);
    $newOwner = Str::uuid()->toString();
    $newGuardAt = now()->startOfSecond();

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $newOwner, $newGuardAt, $throwOnGet) {
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => $newGuardAt,
                'status' => 'inactive',
                'management_state' => 'paused',
                'metadata' => ['token_status' => 'verified_saved_token', 'newer_reconcile_winner' => true],
            ]);
            if ($throwOnGet) {
                throw new \Illuminate\Http\Client\ConnectionException('Timeout');
            }
            return Http::response([
                'id' => $sub->subscription_id,
                'amount' => '50000',
                'currency' => 'IDR',
                'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
            ], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.reconcile', $sub))
        ->assertStatus(409)
        ->assertJson(['success' => false, 'management_state' => 'paused']);

    $fresh = $sub->fresh();
    expect($fresh->operation_guard_owner)->toBe($newOwner);
    expect($fresh->operation_guard_at?->toIso8601String())->toBe($newGuardAt->toIso8601String());
    expect($fresh->metadata['newer_reconcile_winner'] ?? null)->toBeTrue();
})->with([
    'GET success' => [false],
    'GET exception' => [true],
]);

// ============================================================
// E5.1 FINAL SIGN-OFF: SECTION 8.3 & SECTION 10 ACCEPTANCE MATRIX TESTS
// ============================================================

test('E5.1: 8.3 malformed provider currency runtime types and wrong currency fail closed with 422 without HTTP 500', function (mixed $badCurrency) {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription();
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => $badCurrency,
            'status' => 'active',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(422)
        ->assertJson(['success' => false]);

    expect($sub->fresh()->management_state)->toBe('active');
    expect($sub->fresh()->operation_guard_owner)->toBeNull();
    Http::assertNotSent(fn ($req) => $req->method() === 'POST');
})->with([
    'USD' => ['USD'],
    'empty string' => [''],
    'null' => [null],
    'array' => [['IDR']],
    'true' => [true],
    'false' => [false],
    'int' => [123],
]);

test('E5.1: 8.3 whitespace and mixed-case provider statuses normalize consistently for cancel and pause', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::response([
            'id' => $sub->subscription_id,
            'amount' => '50000',
            'currency' => ' idr ',
            'status' => '  Canceled  ',
            'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
        ], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'cancelled']);

    $fresh = $sub->fresh();
    expect($fresh->metadata['cancellation']['provider_confirmed'])->toBeTrue();
    expect($fresh->metadata['cancellation']['provider_status'])->toBe('canceled');
    Http::assertNotSent(fn ($req) => $req->method() === 'POST');
});

test('E5.1: 10 canonical management API acceptance matrix verifies auth contract exact responses and zero financial side effects', function () {
    ['campaign' => $campaign, 'donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'token' => 'secret-tok-10',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $initialDonations = Donation::count();
    $initialProgress = (int) $campaign->terkumpul_nominal;

    // GET /donatur/subscriptions uses JSON list without exposing token
    $indexRes = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'))
        ->assertOk()
        ->assertJsonStructure([
            'success',
            'subscriptions' => [
                ['id', 'subscription_id', 'status', 'management_state', 'frequency', 'nominal', 'next_execution_at'],
            ],
        ]);
    expect($indexRes->getContent())->not->toContain('secret-tok-10');

    // Full Pause -> Resume -> Repeated Resume -> Cancel -> Pause/Resume blocked lifecycle
    $disablePosts = 0;
    $enablePosts = 0;
    $cancelPosts = 0;
    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
            ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200),
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => function () use (&$disablePosts) {
            $disablePosts++;
            return Http::response(['status' => 'inactive'], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/enable' => function () use (&$enablePosts) {
            $enablePosts++;
            return Http::response(['status' => 'active'], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/cancel' => function () use (&$cancelPosts) {
            $cancelPosts++;
            return Http::response(['status_message' => 'Subscription is updated.'], 200);
        },
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'paused']);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active']);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'active', 'management_state' => 'active', 'message' => 'Subscription sudah aktif.']);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.cancel', $sub))
        ->assertOk()
        ->assertJson(['success' => true, 'status' => 'inactive', 'management_state' => 'cancelled']);

    $recordedBeforeBlockedCalls = count(Http::recorded());
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))->assertStatus(422);
    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))->assertStatus(422);
    expect(count(Http::recorded()))->toBe($recordedBeforeBlockedCalls);

    expect($disablePosts)->toBe(1);
    expect($enablePosts)->toBe(1);
    expect($cancelPosts)->toBe(1);
    expect(Donation::count())->toBe($initialDonations);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($initialProgress);
});

// ============================================================
// E5.1-TZ: SCHEDULE SYNCHRONIZATION, GUARD OWNERSHIP & SERIALIZATION TESTS
// ============================================================

test('TZ: management pause resume and reconcile synchronize explicit +07:00 and documented GMT+7 offset-free schedules to UTC inside guard transaction', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    try {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-10 05:00:00',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'schedule' => ['next_execution_source' => 'provider_confirmed', 'next_execution_utc' => '2026-12-10T05:00:00Z'],
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-10T12:00:00+07:00']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2027-01-10T00:15:00+07:00']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2027-01-10 09:00:00']], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/enable' => Http::response(['status' => 'active'], 200),
        ]);

        // 1. Pause syncs valid explicit +07:00 crossing midnight to canonical UTC 2027-01-09T17:15:00Z
        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertOk()
            ->assertJson(['management_state' => 'paused']);
        $pausedFresh = $sub->fresh();
        expect($pausedFresh->next_execution_at?->toIso8601ZuluString())->toBe('2027-01-09T17:15:00Z');
        expect($pausedFresh->metadata['schedule']['next_execution_source'])->toBe('provider_confirmed');
        expect($pausedFresh->metadata['schedule']['timezone_resolution'])->toBe('explicit_offset');
        expect($pausedFresh->metadata['schedule']['assumed_offset'])->toBeNull();
        expect($pausedFresh->metadata['schedule']['source_context'])->toBe('midtrans_subscription_get');

        // 2. Resume with documented GMT+7 offset-free schedule (2027-01-10 09:00:00) normalizes to 2027-01-10T02:00:00Z
        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $sub))
            ->assertOk()
            ->assertJson(['management_state' => 'active']);
        $resumedFresh = $sub->fresh();
        expect($resumedFresh->next_execution_at?->toIso8601ZuluString())->toBe('2027-01-10T02:00:00Z');
        expect($resumedFresh->metadata['schedule'])->toMatchArray([
            'next_execution_source' => 'provider_confirmed',
            'next_execution_utc' => '2027-01-10T02:00:00Z',
            'freshness' => 'fresh',
            'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
            'assumed_offset' => '+07:00',
            'source_context' => 'midtrans_subscription_get',
        ]);
        expect($resumedFresh->operation_guard_at)->toBeNull();

        $indexRes = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'))->assertOk();
        $row = collect($indexRes->json('subscriptions'))->firstWhere('subscription_id', $sub->subscription_id);
        expect($row['schedule']['status'])->toBe('confirmed');
        expect($row['schedule']['display_wib'])->toBe('10 Januari 2027, 09:00:00 WIB');
        expect($row['schedule']['is_displayable_upcoming'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('TZ: ownership transfer during post-mutation GET returns 409 and never overwrites newer owner schedule', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'active',
        'management_state' => 'active',
        'next_execution_at' => '2026-12-10 05:00:00',
        'metadata' => ['token_status' => 'verified_saved_token'],
    ]);
    $newOwner = Str::uuid()->toString();
    $getCall = 0;

    Http::fake([
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => function () use ($sub, $newOwner, &$getCall) {
            $getCall++;
            if ($getCall === 1) {
                return Http::response([
                    'id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active',
                    'schedule' => ['interval' => 1, 'interval_unit' => 'month'],
                ], 200);
            }
            Subscription::whereKey($sub->id)->update([
                'operation_guard_owner' => $newOwner,
                'operation_guard_at' => now(),
                'next_execution_at' => '2027-02-01 03:00:00',
                'management_state' => 'paused',
                'status' => 'inactive',
            ]);
            return Http::response([
                'id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive',
                'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2026-12-24T13:23:36+07:00'],
            ], 200);
        },
        'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
    ]);

    $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
        ->assertStatus(409);

    $fresh = $sub->fresh();
    expect($fresh->next_execution_at?->toIso8601ZuluString())->toBe('2027-02-01T03:00:00Z');
    expect($fresh->operation_guard_owner)->toBe($newOwner);
});

test('TZ: management index serializes explicit ISO 8601 UTC or null without exposing unverified historical dates or cancelled charges', function () {
    $donatur = User::factory()->donatur()->create();
    $penerima = User::factory()->penerima()->create();
    $campaign = Campaign::create([
        'user_id' => $penerima->id, 'judul' => 'TZ API Campaign', 'slug' => 'tz-api-' . Str::random(6),
        'deskripsi' => 'Desc', 'tipe_bantuan' => 'uang', 'target_nominal' => 1000000,
        'terkumpul_nominal' => 0, 'urgensi' => 'biasa',
        'tanggal_mulai' => now()->subDay()->toDateString(), 'tanggal_selesai' => now()->addMonth()->toDateString(), 'status' => 'aktif',
    ]);

    $base = [
        'user_id' => $donatur->id, 'campaign_id' => $campaign->id, 'nominal' => 50000,
        'frequency' => 'bulanan', 'interval' => 1, 'interval_unit' => 'month',
        'payment_type' => 'credit_card', 'token' => 'secret-tz-tok',
    ];

    $sActive = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-act', 'status' => 'active', 'management_state' => 'active',
        'next_execution_at' => '2026-12-24 06:23:36',
        'metadata' => ['schedule' => ['next_execution_source' => 'provider_confirmed', 'next_execution_utc' => '2026-12-24T06:23:36Z']],
    ]));
    $sPaused = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-psd', 'status' => 'inactive', 'management_state' => 'paused',
        'next_execution_at' => '2026-12-24 06:23:36',
        'metadata' => ['schedule' => ['next_execution_source' => 'provider_confirmed', 'next_execution_utc' => '2026-12-24T06:23:36Z']],
    ]));
    $sReconcile = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-rec', 'status' => 'active', 'management_state' => 'reconciliation',
        'next_execution_at' => '2026-12-24 06:23:36',
        'metadata' => ['schedule' => ['next_execution_source' => 'provider_confirmed', 'next_execution_utc' => '2026-12-24T06:23:36Z']],
    ]));
    $sCancelled = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-can', 'status' => 'inactive', 'management_state' => 'cancelled',
        'next_execution_at' => '2026-12-02 22:24:35',
        'metadata' => ['cancellation' => ['provider_confirmed' => true, 'provider_status' => 'inactive', 'confirmed_at' => '2026-11-25T16:39:06+00:00']],
    ]));
    $sHistorical = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-hist', 'status' => 'active', 'management_state' => null,
        'next_execution_at' => '2026-12-24 13:23:36',
        'metadata' => [],
    ]));
    $sUnknown = Subscription::create(array_merge($base, [
        'subscription_id' => 'sub-tz-api-unk', 'status' => 'active', 'management_state' => 'active',
        'next_execution_at' => null,
        'metadata' => ['schedule' => ['next_execution_source' => 'unavailable']],
    ]));

    $res = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'));
    $res->assertOk();
    expect($res->getContent())->not->toContain('secret-tz-tok');
    expect($res->getContent())->not->toContain('token');

    $byId = collect($res->json('subscriptions'))->keyBy('subscription_id');

    expect($byId[$sActive->subscription_id]['next_execution_at'])->toBe('2026-12-24T06:23:36Z');
    expect($byId[$sActive->subscription_id]['schedule'])->toBe([
        'status' => 'confirmed',
        'next_execution_at_utc' => '2026-12-24T06:23:36Z',
        'display_timezone' => 'Asia/Jakarta',
        'is_upcoming_billing_guaranteed' => false,
        'freshness' => 'fresh',
        'is_past' => false,
        'is_displayable_upcoming' => true,
        'display_wib' => '24 Desember 2026, 13:23:36 WIB',
        'last_synced_at' => null,
    ]);

    expect($byId[$sPaused->subscription_id]['next_execution_at'])->toBe('2026-12-24T06:23:36Z');
    expect($byId[$sPaused->subscription_id]['schedule']['status'])->toBe('paused_not_scheduled');
    expect($byId[$sPaused->subscription_id]['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

    expect($byId[$sReconcile->subscription_id]['schedule']['status'])->toBe('pending_reconciliation');
    expect($byId[$sReconcile->subscription_id]['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

    expect($byId[$sCancelled->subscription_id]['next_execution_at'])->toBeNull();
    expect($byId[$sCancelled->subscription_id]['schedule']['status'])->toBe('cancelled_no_upcoming_charge');
    expect($byId[$sCancelled->subscription_id]['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

    expect($byId[$sHistorical->subscription_id]['next_execution_at'])->toBeNull();
    expect($byId[$sHistorical->subscription_id]['schedule']['status'])->toBe('historical_unverified');
    expect($byId[$sHistorical->subscription_id]['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

    expect($byId[$sUnknown->subscription_id]['next_execution_at'])->toBeNull();
    expect($byId[$sUnknown->subscription_id]['schedule']['status'])->toBe('unavailable');
    expect($byId[$sUnknown->subscription_id]['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();
});

test('E5.5-C.1: management refresh preserves existing GMT+7 provenance as stale when later GET returns missing or invalid schedule', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    try {
        ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2027-01-10 02:00:00',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2027-01-10T02:00:00Z',
                    'freshness' => 'fresh',
                    'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
                    'assumed_offset' => '+07:00',
                    'source_context' => 'midtrans_subscription_create',
                ],
            ],
        ]);

        Http::fake([
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id => Http::sequence()
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'active', 'schedule' => ['interval' => 1, 'interval_unit' => 'month']], 200)
                ->push(['id' => $sub->subscription_id, 'amount' => '50000', 'currency' => 'IDR', 'status' => 'inactive', 'schedule' => ['interval' => 1, 'interval_unit' => 'month', 'next_execution_at' => '2027-01-10']], 200),
            'https://api.sandbox.midtrans.com/v1/subscriptions/' . $sub->subscription_id . '/disable' => Http::response(['status' => 'inactive'], 200),
        ]);

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $sub))
            ->assertOk()
            ->assertJson(['management_state' => 'paused', 'operation_in_progress' => false]);

        $fresh = $sub->fresh();
        expect($fresh->operation_guard_at)->toBeNull();
        expect($fresh->next_execution_at?->toIso8601ZuluString())->toBe('2027-01-10T02:00:00Z');
        expect($fresh->metadata['schedule']['next_execution_utc'])->toBe('2027-01-10T02:00:00Z');
        expect($fresh->metadata['schedule']['timezone_resolution'])->toBe('midtrans_subscription_gmt7_contract');
        expect($fresh->metadata['schedule']['assumed_offset'])->toBe('+07:00');
        expect($fresh->metadata['schedule']['source_context'])->toBe('midtrans_subscription_create');
        expect($fresh->metadata['schedule']['freshness'])->toBe('stale_preserved');
        expect($fresh->metadata['schedule']['last_refresh_status'])->toBe('invalid');
        expect($fresh->metadata['schedule']['last_refresh_reason'])->toBe('date_only_timestamp');

        // Paused subscription never advertises display_wib or displayable upcoming charge
        $indexRes = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'))->assertOk();
        $row = collect($indexRes->json('subscriptions'))->firstWhere('subscription_id', $sub->subscription_id);
        expect($row['schedule']['status'])->toBe('paused_not_scheduled');
        expect($row['schedule']['is_displayable_upcoming'])->toBeFalse();
        expect($row['schedule']['display_wib'])->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.5-C.1: cancelled terminal-state protection, offset-free canonical rejection, and legacy compatibility remain intact', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    Http::preventStrayRequests();
    try {
        ['donatur' => $donatur, 'subscription' => $subCancelled] = createManagedSubscription([
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'next_execution_at' => '2026-12-02 22:24:35',
            'metadata' => [
                'token_status' => 'verified_saved_token',
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03T07:27:12Z',
                    'freshness' => 'fresh',
                ],
            ],
        ]);

        // Subscription whose stored canonical metadata was corrupted with offset-free string (no Z/+07:00)
        $subCorruptedCanonical = Subscription::create([
            'subscription_id' => 'sub-corrupt-canon-' . Str::random(5),
            'user_id' => $donatur->id,
            'campaign_id' => $subCancelled->campaign_id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'tok-corrupt-canon',
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 13:23:36',
            'metadata' => [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24 13:23:36',
                    'freshness' => 'fresh',
                ],
            ],
        ]);

        // Legacy confirmed canonical metadata without timezone_resolution/assumed_offset/source_context
        $subLegacyCompat = Subscription::create([
            'subscription_id' => 'sub-legacy-compat-' . Str::random(5),
            'user_id' => $donatur->id,
            'campaign_id' => $subCancelled->campaign_id,
            'nominal' => 50000,
            'frequency' => 'bulanan',
            'interval' => 1,
            'interval_unit' => 'month',
            'payment_type' => 'credit_card',
            'token' => 'tok-legacy-compat',
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-24 06:23:36',
            'metadata' => [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                    'synced_at' => '2026-11-26T06:00:00Z',
                ],
            ],
        ]);

        Http::fake();

        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.pause', $subCancelled))->assertStatus(422);
        $this->actingAs($donatur)->postJson(route('donatur.subscriptions.resume', $subCancelled))->assertStatus(422);
        expect(count(Http::recorded()))->toBe(0);
        expect($subCancelled->fresh()->metadata['cancellation']['confirmed_at'])->toBe('2026-11-25T16:39:06+00:00');

        $indexRes = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'))->assertOk();
        $byId = collect($indexRes->json('subscriptions'))->keyBy('subscription_id');

        expect($byId[$subCancelled->subscription_id]['schedule']['status'])->toBe('cancelled_no_upcoming_charge');
        expect($byId[$subCancelled->subscription_id]['next_execution_at'])->toBeNull();

        expect($byId[$subCorruptedCanonical->subscription_id]['schedule']['status'])->toBe('historical_unverified');
        expect($byId[$subCorruptedCanonical->subscription_id]['next_execution_at'])->toBeNull();
        expect($byId[$subCorruptedCanonical->subscription_id]['schedule']['is_displayable_upcoming'])->toBeFalse();

        expect($byId[$subLegacyCompat->subscription_id]['schedule']['status'])->toBe('confirmed');
        expect($byId[$subLegacyCompat->subscription_id]['next_execution_at'])->toBe('2026-12-24T06:23:36Z');
        expect($byId[$subLegacyCompat->subscription_id]['schedule']['display_wib'])->toBe('24 Desember 2026, 13:23:36 WIB');
    } finally {
        Carbon::setTestNow();
    }
});
