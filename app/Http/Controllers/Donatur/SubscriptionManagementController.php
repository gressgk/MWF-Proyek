<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\SubscriptionManagementService;
use App\Services\SubscriptionScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubscriptionManagementController extends Controller
{
    public function index(SubscriptionScheduleService $scheduleService): JsonResponse
    {
        Gate::authorize('viewAny', Subscription::class);
        $subscriptions = Subscription::where('user_id', getAuthUser()->id)
            ->with(['campaign:id,judul,slug'])
            ->orderByDesc('id')
            ->get()
            ->map(function (Subscription $subscription) use ($scheduleService): array {
                $scheduleData = $scheduleService->serializeForApi($subscription);

                return [
                    'id' => $subscription->id,
                    'subscription_id' => $subscription->subscription_id,
                    'campaign_title' => $subscription->campaign?->judul ?? 'Campaign tidak tersedia',
                    'campaign_slug' => $subscription->campaign?->slug,
                    'status' => $subscription->status,
                    'management_state' => $subscription->management_state,
                    'operation_in_progress' => $subscription->isOperationInProgress(),
                    'frequency' => $subscription->frequency,
                    'nominal' => (int) $subscription->nominal,
                    'next_execution_at' => $scheduleData['next_execution_at'],
                    'schedule' => $scheduleData['schedule'],
                    'endpoints' => [
                        'pause' => route('donatur.subscriptions.pause', ['subscription' => $subscription->id]),
                        'resume' => route('donatur.subscriptions.resume', ['subscription' => $subscription->id]),
                        'cancel' => route('donatur.subscriptions.cancel', ['subscription' => $subscription->id]),
                        'reconcile' => route('donatur.subscriptions.reconcile', ['subscription' => $subscription->id]),
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function pause(Subscription $subscription, SubscriptionManagementService $service): JsonResponse
    {
        Gate::authorize('manage', $subscription);
        return $this->respond($subscription, $service->pause($subscription));
    }

    public function resume(Subscription $subscription, SubscriptionManagementService $service): JsonResponse
    {
        Gate::authorize('manage', $subscription);
        return $this->respond($subscription, $service->resume($subscription));
    }

    public function cancel(Subscription $subscription, SubscriptionManagementService $service): JsonResponse
    {
        Gate::authorize('manage', $subscription);
        return $this->respond($subscription, $service->cancel($subscription));
    }

    public function reconcile(Subscription $subscription, SubscriptionManagementService $service): JsonResponse
    {
        Gate::authorize('manage', $subscription);
        return $this->respond($subscription, $service->reconcile($subscription));
    }

    protected function respond(Subscription $subscription, array $result): JsonResponse
    {
        $statusCode = $result['http_status'] ?? (($result['success'] ?? false) ? 200 : 422);
        $payload = [
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'] ?? $subscription->status,
            'management_state' => $result['management_state'] ?? $subscription->management_state,
            'operation_in_progress' => (bool) ($result['operation_in_progress'] ?? ($subscription->fresh()?->isOperationInProgress() ?? false)),
            'message' => $result['message'] ?? 'Operasi subscription selesai.',
        ];
        if (isset($result['conflict_reason']) && is_string($result['conflict_reason'])) {
            $payload['conflict_reason'] = $result['conflict_reason'];
        }

        return response()->json($payload, $statusCode);
    }
}
