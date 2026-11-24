<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\SubscriptionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubscriptionManagementController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Subscription::class);
        $subscriptions = Subscription::where('user_id', getAuthUser()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'subscription_id' => $subscription->subscription_id,
                'status' => $subscription->status,
                'management_state' => $subscription->management_state,
                'frequency' => $subscription->frequency,
                'nominal' => (int) $subscription->nominal,
                'next_execution_at' => $subscription->next_execution_at,
            ]);

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
        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'] ?? $subscription->status,
            'management_state' => $result['management_state'] ?? $subscription->management_state,
            'message' => $result['message'] ?? 'Operasi subscription selesai.',
        ], $statusCode);
    }
}
