<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionScheduleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Strict isolation: render purely from in-memory synthetic objects with ZERO database queries.
DB::beforeExecuting(function () {
    throw new \RuntimeException('SAFETY VIOLATION: Browser fixture renderer must not execute database queries.');
});

$scenario = $argv[1] ?? 'settings-full';
$baseUrl = rtrim($argv[2] ?? 'http://127.0.0.1:8000', '/');

URL::forceRootUrl($baseUrl);
Config::set('app.url', $baseUrl);
Config::set('services.midtrans.client_key', 'SB-Mid-client-SYNTHETIC-TEST');
Config::set('services.midtrans.is_production', false);

Session::start();
Session::put('_token', 'e2e-synthetic-csrf-token-001');
View::share('errors', new ViewErrorBag());

$user = (new User())->forceFill([
    'id' => 9001,
    'name' => 'Donatur Uji Sintetis',
    'email' => 'donatur.sintetis@example.test',
    'phone' => '081200000001',
    'role' => 'donatur',
]);
Auth::setUser($user);

Carbon::setTestNow(Carbon::parse('2026-11-26 04:00:00', 'UTC'));

try {
    if ($scenario === 'checkout-recurring') {
        $campaign = (new Campaign())->forceFill([
            'id' => 7001,
            'judul' => 'Campaign Checkout Sintetis',
            'slug' => 'campaign-checkout-sintetis',
            'tipe_bantuan' => 'uang',
            'status' => 'aktif',
        ]);
        $idempotencyKey = 'idem-e2e-checkout-001';

        echo view('donatur.donasi-uang', compact('campaign', 'idempotencyKey'))->render();
        exit(0);
    }

    $scheduleService = app(SubscriptionScheduleService::class);

    $makeItem = function (
        int $id,
        string $campaignTitle,
        int $nominal,
        string $frequency,
        string $status,
        ?string $managementState,
        ?string $nextExecutionAt,
        array $metadata = [],
        bool $operationInProgress = false
    ) use ($scheduleService, $user): array {
        $sub = (new Subscription())->forceFill([
            'id' => $id,
            'subscription_id' => 'sub-synth-' . $id,
            'user_id' => $user->id,
            'campaign_id' => 8000 + $id,
            'nominal' => $nominal,
            'frequency' => $frequency,
            'interval' => 1,
            'interval_unit' => $frequency === 'mingguan' ? 'week' : 'month',
            'payment_type' => 'credit_card',
            'status' => $status,
            'management_state' => $managementState,
            'next_execution_at' => $nextExecutionAt,
            'metadata' => $metadata,
        ]);

        $scheduleData = $scheduleService->serializeForApi($sub);

        return [
            'id' => $id,
            'subscription_id' => $sub->subscription_id,
            'campaign_title' => $campaignTitle,
            'campaign_slug' => null,
            'nominal' => $nominal,
            'nominal_formatted' => 'Rp' . number_format($nominal, 0, ',', '.'),
            'frequency' => $frequency,
            'frequency_label' => $frequency === 'mingguan' ? 'Mingguan' : 'Bulanan',
            'status' => $status,
            'management_state' => $managementState,
            'operation_in_progress' => $operationInProgress,
            'next_execution_at' => $scheduleData['next_execution_at'],
            'schedule' => $scheduleData['schedule'],
            'endpoints' => [
                'pause' => route('donatur.subscriptions.pause', ['subscription' => $id]),
                'resume' => route('donatur.subscriptions.resume', ['subscription' => $id]),
                'cancel' => route('donatur.subscriptions.cancel', ['subscription' => $id]),
                'reconcile' => route('donatur.subscriptions.reconcile', ['subscription' => $id]),
            ],
        ];
    };

    if ($scenario === 'settings-empty') {
        $subscriptionItems = [];
    } elseif ($scenario === 'settings-901-paused') {
        $subscriptionItems = [
            $makeItem(901, 'Campaign Bantuan Banjir', 50000, 'bulanan', 'inactive', 'paused', '2026-12-24 06:23:36', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                ],
            ]),
        ];
    } elseif ($scenario === 'settings-901-reconciliation') {
        $subscriptionItems = [
            $makeItem(901, 'Campaign Bantuan Banjir', 50000, 'bulanan', 'active', 'reconciliation', '2026-12-24 06:23:36', []),
        ];
    } elseif ($scenario === 'settings-screenshot') {
        $subscriptionItems = [
            $makeItem(901, 'Campaign Bantuan Pangan', 50000, 'bulanan', 'active', 'active', '2026-12-24 06:23:36', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                ],
            ]),
            $makeItem(902, 'Campaign Pendidikan Anak', 25000, 'mingguan', 'inactive', 'paused', '2026-12-03 06:00:00', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03T06:00:00Z',
                    'freshness' => 'fresh',
                ],
            ]),
            $makeItem(903, 'Campaign Kesehatan Warga', 100000, 'bulanan', 'inactive', 'cancelled', '2026-12-02 22:24:35', [
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
            ]),
            $makeItem(904, 'Campaign Pemulihan Bencana', 75000, 'bulanan', 'active', 'reconciliation', null, []),
        ];
    } else {
        $subscriptionItems = [
            $makeItem(901, 'Campaign Bantuan Banjir', 50000, 'bulanan', 'active', 'active', '2026-12-24 06:23:36', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-24T06:23:36Z',
                    'freshness' => 'fresh',
                ],
            ]),
            $makeItem(902, 'Campaign Pendidikan Anak', 25000, 'mingguan', 'inactive', 'paused', '2026-12-03 06:00:00', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03T06:00:00Z',
                    'freshness' => 'fresh',
                ],
            ]),
            $makeItem(903, 'Campaign Kesehatan Warga', 100000, 'bulanan', 'inactive', 'cancelled', '2026-12-02 22:24:35', [
                'cancellation' => [
                    'provider_confirmed' => true,
                    'provider_status' => 'inactive',
                    'confirmed_at' => '2026-11-25T16:39:06+00:00',
                ],
            ]),
            $makeItem(904, 'Campaign Pangan Keluarga', 75000, 'bulanan', 'active', 'reconciliation', null, []),
            $makeItem(905, 'Campaign Historis Uji', 50000, 'bulanan', 'active', null, '2026-12-24 13:23:36', []),
            $makeItem(906, 'Campaign Jadwal Lampau', 30000, 'bulanan', 'active', 'active', '2026-11-20 06:00:00', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-11-20T06:00:00Z',
                    'freshness' => 'fresh',
                ],
            ]),
            $makeItem(907, 'Campaign Jadwal Stale', 40000, 'bulanan', 'active', 'active', '2026-12-28 06:00:00', [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-28T06:00:00Z',
                    'freshness' => 'stale_preserved',
                ],
            ]),
            $makeItem(908, 'Campaign tidak tersedia', 35000, 'bulanan', 'active', 'active', null, [
                'schedule' => ['next_execution_source' => 'unavailable'],
            ]),
            $makeItem(909, 'Campaign Status Belum Pasti', 60000, 'bulanan', 'inactive', null, null, []),
            $makeItem(910, 'Campaign State Tidak Dikenal', 20000, 'bulanan', 'suspended_custom', null, null, []),
            $makeItem(911, 'Campaign Guard Aktif', 80000, 'bulanan', 'active', 'active', null, [], true),
            $makeItem(912, '<img src=x onerror="window.__xssTriggered=true"> Campaign XSS Uji', 45000, 'bulanan', 'active', 'active', null, []),
        ];
    }

    $subscriptionsIndexUrl = route('donatur.subscriptions.index');
    echo view('donatur.settings', compact('user', 'subscriptionItems', 'subscriptionsIndexUrl'))->render();
} finally {
    Carbon::setTestNow();
}
