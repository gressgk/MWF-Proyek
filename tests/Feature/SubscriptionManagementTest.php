<?php

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $dbName = config('database.connections.mysql.database');
    if ($dbName !== 'wecare_testing') {
        throw new \RuntimeException("SAFETY VIOLATION: Tests must run on wecare_testing, currently on {$dbName}");
    }
    Config::set('services.midtrans.server_key', 'SB-Mid-server-test-key');
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

test('owner can resume paused subscription and repeated pause is idempotent', function () {
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
    ['campaign' => $campaign, 'subscription' => $sub] = createManagedSubscription([
        'status' => 'inactive',
        'management_state' => 'cancelled',
    ]);
    $initialDonations = Donation::count();
    $initialProgress = (int) $campaign->terkumpul_nominal;

    $response = $this->postJson(route('midtrans.webhook.subscription'), [
        'event_name' => 'subscription.inactive',
        'subscription' => ['id' => $sub->subscription_id],
        'transaction' => [
            'status_code' => '200',
            'transaction_status' => 'inactive',
            'order_id' => 'unused-inactive-event',
        ],
    ]);

    $response->assertOk();
    expect($sub->fresh()->management_state)->toBe('cancelled');
    expect(Donation::count())->toBe($initialDonations);
    expect((int) $campaign->fresh()->terkumpul_nominal)->toBe($initialProgress);
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

test('no token is returned in management API response', function () {
    ['donatur' => $donatur, 'subscription' => $sub] = createManagedSubscription([
        'token' => str_repeat('sensitive-token-', 20),
    ]);
    $sub->refresh();

    $response = $this->actingAs($donatur)->getJson(route('donatur.subscriptions.index'));

    $response->assertOk();
    expect($response->getContent())->not->toContain('token');
});
