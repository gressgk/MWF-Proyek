<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionManagementService
{
    public function __construct(
        protected MidtransPaymentService $midtrans
    ) {}

    public function pause(Subscription $subscription): array
    {
        return $this->manage($subscription, 'pause');
    }

    public function resume(Subscription $subscription): array
    {
        return $this->manage($subscription, 'resume');
    }

    public function cancel(Subscription $subscription): array
    {
        return $this->manage($subscription, 'cancel');
    }

    public function reconcile(Subscription $subscription): array
    {
        $subscription->refresh();
        if ($subscription->management_state !== 'reconciliation') {
            try {
                $remote = $this->midtrans->getSubscription($subscription->subscription_id);
                $verified = $this->verifyProviderSubscription($subscription, $remote);
                if (!$verified['valid']) {
                    return $this->failure($subscription, $verified['message'], 422);
                }
                return [
                    'success' => true,
                    'status' => $subscription->status,
                    'management_state' => $subscription->management_state,
                    'message' => 'Tidak ada reconciliation pending. Status provider terverifikasi.',
                ];
            } catch (\Throwable $e) {
                return $this->failure($subscription, 'Status provider belum dapat diverifikasi.', 503);
            }
        }

        try {
            $remote = $this->midtrans->getSubscription($subscription->subscription_id);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Reconciliation belum selesai; provider belum dapat diperiksa.',
                'http_status' => 202,
            ];
        }

        $verified = $this->verifyProviderSubscription($subscription, $remote);
        if (!$verified['valid']) {
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Reconciliation belum selesai karena data provider tidak dapat dipastikan.',
                'http_status' => 202,
            ];
        }

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $lastOperation = $metadata['last_management_operation'] ?? null;
        $remoteStatus = strtolower((string) ($remote['status'] ?? ''));

        if ($lastOperation === 'pause' && $remoteStatus === 'inactive') {
            return $this->completeReconciliation($subscription, 'inactive', 'paused', 'Reconciliation selesai; Subscription telah dijeda.');
        }
        if ($lastOperation === 'resume' && $remoteStatus === 'active') {
            return $this->completeReconciliation($subscription, 'active', 'active', 'Reconciliation selesai; Subscription telah aktif.');
        }
        if ($lastOperation === 'cancel') {
            $explicitCancelled = in_array($remoteStatus, ['cancelled', 'canceled'], true);
            if ($explicitCancelled) {
                return $this->completeReconciliation($subscription, 'inactive', 'cancelled', 'Reconciliation selesai; Subscription telah dibatalkan.');
            }
            $hasVerifiedEvidence = isset($metadata['cancellation']['provider_confirmed'])
                && $metadata['cancellation']['provider_confirmed'] === true
                && in_array($metadata['cancellation']['provider_status'] ?? null, ['inactive', 'pending_inactive_verification'], true);
            if ($hasVerifiedEvidence && $remoteStatus === 'inactive') {
                return $this->confirmCancellation(
                    $subscription,
                    'Reconciliation selesai; pembatalan terverifikasi.'
                );
            }
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Bukti pembatalan masih ambigu. Status inactive saja tidak membuktikan Cancel.',
                'http_status' => 202,
            ];
        }

        return [
            'success' => false,
            'status' => $subscription->status,
            'management_state' => 'reconciliation',
            'message' => 'Operasi reconciliation tidak dapat dipastikan.',
            'http_status' => 202,
        ];
    }

    protected function manage(Subscription $subscription, string $operation): array
    {
        $guard = $this->acquireGuard($subscription);
        if (!$guard['acquired']) {
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => $subscription->management_state,
                'message' => 'Operasi subscription sedang berjalan atau baru saja dimulai.',
                'http_status' => 409,
            ];
        }

        $subscription->refresh();
        try {
            $state = $subscription->management_state;
            if ($state === 'reconciliation') {
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => 'reconciliation',
                    'message' => 'Subscription menunggu reconciliation. Mutation baru diblokir.',
                    'http_status' => 409,
                ];
            }
            if ($operation === 'pause') {
                if ($state === 'cancelled') {
                    return $this->failure($subscription, 'Subscription yang dibatalkan tidak dapat dijeda.', 422);
                }
            }
            if ($operation === 'resume') {
                if ($state === 'cancelled') {
                    return $this->failure($subscription, 'Subscription yang dibatalkan tidak dapat dilanjutkan.', 422);
                }
            }
            if ($operation === 'cancel') {
                if ($state === 'cancelled') {
                    return $this->verifyCancelledNoOp($subscription);
                }
            }

            $remoteBefore = $this->midtrans->getSubscription($subscription->subscription_id);
            $verified = $this->verifyProviderSubscription($subscription, $remoteBefore);
            if (!$verified['valid']) {
                return $this->failure($subscription, $verified['message'], 422);
            }

            if ($operation === 'pause' && $state === 'paused') {
                $providerStatus = strtolower((string) $remoteBefore['status']);
                if ($providerStatus === 'inactive') {
                    return $this->applyLocalState($subscription, 'inactive', 'paused', 'Subscription sudah dijeda.');
                }
                if ($providerStatus === 'active') {
                    $this->setReconciliation($subscription, 'pause');
                    return [
                        'success' => false,
                        'status' => $subscription->status,
                        'management_state' => 'reconciliation',
                        'message' => 'Status lokal dijeda tetapi provider masih aktif. Pemeriksaan reconciliation diperlukan.',
                        'http_status' => 202,
                    ];
                }
            }
            if ($operation === 'resume' && ($subscription->status === 'active' || $state === 'active')) {
                $providerStatus = strtolower((string) $remoteBefore['status']);
                if ($providerStatus === 'active') {
                    return $this->applyLocalState($subscription, 'active', 'active', 'Subscription sudah aktif.');
                }
                if ($providerStatus === 'inactive') {
                    $this->setReconciliation($subscription, 'resume');
                    return [
                        'success' => false,
                        'status' => $subscription->status,
                        'management_state' => 'reconciliation',
                        'message' => 'Resume tidak berhasil; provider masih inactive.',
                        'http_status' => 202,
                    ];
                }
            }
            if ($operation === 'pause' && strtolower((string) $remoteBefore['status']) === 'inactive') {
                return $this->applyLocalState($subscription, 'inactive', 'paused', 'Subscription sudah dinonaktifkan oleh provider.');
            }
            if ($operation === 'resume' && strtolower((string) $remoteBefore['status']) === 'active') {
                return $this->applyLocalState($subscription, 'active', 'active', 'Subscription sudah aktif di provider.');
            }
            if ($operation === 'cancel' && strtolower((string) $remoteBefore['status']) === 'cancelled') {
                return $this->applyLocalState($subscription, 'cancelled', 'cancelled', 'Subscription sudah dibatalkan di provider.');
            }

            $mutationSucceeded = false;
            $mutationTimedOut = false;
            $cancelResponseConfirmed = false;
            $cancelResponse = null;
            try {
                if ($operation === 'pause') {
                    $this->midtrans->disableSubscription($subscription->subscription_id);
                } elseif ($operation === 'resume') {
                    $this->midtrans->enableSubscription($subscription->subscription_id);
                } else {
                    $cancelResponse = $this->midtrans->cancelSubscription($subscription->subscription_id);
                    $cancelResponseConfirmed = is_array($cancelResponse)
                        && ($cancelResponse['_http_status'] ?? null) === 200
                        && array_key_exists('status_message', $cancelResponse)
                        && is_string($cancelResponse['status_message'])
                        && $cancelResponse['status_message'] === 'Subscription is updated.';
                    if ($cancelResponseConfirmed) {
                        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
                        $metadata['cancellation'] = [
                            'provider_confirmed' => true,
                            'provider_status' => 'pending_inactive_verification',
                            'confirmed_at' => now()->toIso8601String(),
                        ];
                        $subscription->update([
                            'management_state' => 'reconciliation',
                            'metadata' => array_merge($metadata, [
                                'last_management_operation' => 'cancel',
                                'management_reconciliation' => 'provider_status_pending',
                            ]),
                        ]);
                    }
                }
                $mutationSucceeded = true;
            } catch (MidtransConnectionException $e) {
                $mutationTimedOut = true;
                Log::warning('Midtrans subscription mutation ambiguous', [
                    'subscription_id' => $subscription->subscription_id,
                    'operation' => $operation,
                ]);
            } catch (MidtransApiException $e) {
                Log::warning('Midtrans subscription mutation rejected', [
                    'subscription_id' => $subscription->subscription_id,
                    'operation' => $operation,
                ]);
                if ($e->getStatusCode() >= 500 && $e->getStatusCode() <= 599) {
                    $this->setReconciliation($subscription, $operation);
                    return [
                        'success' => false,
                        'status' => $subscription->status,
                        'management_state' => 'reconciliation',
                        'message' => 'HTTP 5xx dapat berarti operasi sudah diterima. Lakukan reconciliation sebelum mutation lain.',
                        'http_status' => 202,
                    ];
                }
                return $this->failure($subscription, 'Operasi ditolak oleh provider atau tidak dapat diselesaikan.', 503);
            }

            try {
                $remoteAfter = $this->midtrans->getSubscription($subscription->subscription_id);
            } catch (\Throwable $e) {
                $this->setReconciliation($subscription, $operation);
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => 'reconciliation',
                    'message' => 'Operasi dikirim atau statusnya belum pasti. Lakukan pemeriksaan provider sebelum mencoba lagi.',
                    'http_status' => 202,
                ];
            }

            $verifiedAfter = $this->verifyProviderSubscription($subscription, $remoteAfter);
            if (!$verifiedAfter['valid']) {
                $this->setReconciliation($subscription, $operation);
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => 'reconciliation',
                    'message' => 'Status provider sesudah operasi tidak dapat dipastikan. Pemeriksaan reconciliation diperlukan.',
                    'http_status' => 202,
                ];
            }

            $remoteStatus = strtolower((string) ($remoteAfter['status'] ?? ''));
            if ($mutationTimedOut) {
                $this->setReconciliation($subscription, $operation);
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => 'reconciliation',
                    'message' => 'Operasi belum dapat dipastikan. Status provider perlu diperiksa sebelum mencoba lagi.',
                    'http_status' => 202,
                ];
            }
            if ($operation === 'pause') {
                if ($remoteStatus === 'inactive') {
                    return $this->applyLocalState($subscription, 'inactive', 'paused', 'Donasi rutin berhasil dijeda.');
                }
            } elseif ($operation === 'resume') {
                if ($remoteStatus === 'active') {
                    return $this->applyLocalState($subscription, 'active', 'active', 'Donasi rutin berhasil dilanjutkan.');
                }
            } else {
                if ($mutationSucceeded && $cancelResponseConfirmed && $remoteStatus === 'inactive') {
                    return $this->confirmCancellation(
                        $subscription,
                        'Donasi rutin berhasil dibatalkan.'
                    );
                }
                if ($mutationSucceeded && $remoteStatus === 'inactive' && !$cancelResponseConfirmed) {
                    $this->setReconciliation($subscription, 'cancel');
                    return [
                        'success' => false,
                        'status' => $subscription->status,
                        'management_state' => 'reconciliation',
                        'message' => 'Respons Cancel tidak lengkap. Pembatalan belum dapat dipastikan.',
                        'http_status' => 202,
                    ];
                }
                if (!$mutationSucceeded) {
                    $this->setReconciliation($subscription, 'cancel');
                    return [
                        'success' => false,
                        'status' => $subscription->status,
                        'management_state' => 'reconciliation',
                        'message' => 'Pembatalan belum dapat dipastikan; lakukan pemeriksaan provider sebelum mencoba lagi.',
                        'http_status' => 202,
                    ];
                }
            }

            if ($operation === 'cancel' && !$mutationSucceeded) {
                $this->setReconciliation($subscription, 'cancel');
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => 'reconciliation',
                    'message' => 'Pembatalan belum dapat dipastikan; lakukan pemeriksaan provider sebelum mencoba lagi.',
                    'http_status' => 202,
                ];
            }

            $this->setReconciliation($subscription, $operation);
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Status provider tidak berubah seperti yang diharapkan; reconciliation diperlukan.',
                'http_status' => 202,
            ];
        } catch (\Throwable $e) {
            Log::warning('Subscription management provider request failed', [
                'subscription_id' => $subscription->subscription_id,
                'operation' => $operation,
            ]);
            return $this->failure($subscription, 'Operasi tidak dapat diselesaikan karena provider.', 503);
        } finally {
            $this->releaseGuard($subscription, $guard['owner']);
        }
    }

    protected function acquireGuard(Subscription $subscription): array
    {
        $ownerToken = Str::uuid()->toString();
        $acquired = DB::transaction(function () use ($subscription, $ownerToken) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$locked) return false;

            $lease = $locked->operation_guard_at;
            $expired = $lease === null || $lease->lte(now()->subMinutes(5));
            if (!$expired && !empty($locked->operation_guard_owner)) {
                return false;
            }
            $locked->update([
                'operation_guard_at' => now(),
                'operation_guard_owner' => $ownerToken,
            ]);
            return true;
        });
        return ['acquired' => $acquired, 'owner' => $ownerToken];
    }

    protected function releaseGuard(Subscription $subscription, string $ownerToken): void
    {
        Subscription::whereKey($subscription->id)
            ->where('operation_guard_owner', $ownerToken)
            ->update([
            'operation_guard_at' => null,
            'operation_guard_owner' => null,
        ]);
    }

    protected function verifyCancelledNoOp(Subscription $subscription): array
    {
        try {
            $remote = $this->midtrans->getSubscription($subscription->subscription_id);
        } catch (\Throwable $e) {
            $this->setReconciliation($subscription, 'cancel');
            $subscription->refresh();
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Pembatalan sebelumnya belum dapat diverifikasi ulang.',
                'http_status' => 202,
            ];
        }

        $verified = $this->verifyProviderSubscription($subscription, $remote);
        if (!$verified['valid']) {
            return $this->failure($subscription, $verified['message'], 422);
        }
        $providerStatus = strtolower((string) ($remote['status'] ?? ''));
        if (in_array($providerStatus, ['cancelled', 'canceled'], true)) {
            return $this->completeReconciliation(
                $subscription,
                'inactive',
                'cancelled',
                'Subscription sudah dibatalkan; tidak ada pembatalan provider baru.'
            );
        }
        if ($providerStatus === 'inactive') {
            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $hasVerifiedEvidence = isset($metadata['cancellation']['provider_confirmed'])
                && $metadata['cancellation']['provider_confirmed'] === true
                && in_array($metadata['cancellation']['provider_status'] ?? null, ['inactive', 'pending_inactive_verification'], true);
            if ($hasVerifiedEvidence) {
                unset($metadata['management_reconciliation'], $metadata['last_management_operation']);
                $subscription->update([
                    'status' => 'inactive',
                    'management_state' => 'cancelled',
                    'metadata' => $metadata,
                ]);
                return [
                    'success' => true,
                    'status' => 'inactive',
                    'management_state' => 'cancelled',
                    'message' => 'Subscription sudah dibatalkan; tidak ada pembatalan provider baru.',
                ];
            }
            $this->setReconciliation($subscription, 'cancel');
            $subscription->refresh();
            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => 'reconciliation',
                'message' => 'Status inactive saja tidak membuktikan Cancel; pemeriksaan lanjutan diperlukan.',
                'http_status' => 202,
            ];
        }
        return $this->failure($subscription, 'Provider tidak memperlihatkan bukti pembatalan.', 422);
    }

    protected function confirmCancellation(
        Subscription $subscription,
        string $message
    ): array {
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        unset($metadata['management_reconciliation'], $metadata['last_management_operation']);
        $confirmedAt = $metadata['cancellation']['confirmed_at'] ?? now()->toIso8601String();
        $metadata['cancellation'] = [
            'provider_confirmed' => true,
            'provider_status' => 'inactive',
            'confirmed_at' => $confirmedAt,
        ];
        $subscription->update([
            'status' => 'inactive',
            'management_state' => 'cancelled',
            'metadata' => $metadata,
        ]);
        return [
            'success' => true,
            'status' => $subscription->status,
            'management_state' => $subscription->management_state,
            'message' => $message,
        ];
    }

    protected function completeReconciliation(
        Subscription $subscription,
        string $status,
        string $managementState,
        string $message
    ): array {
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        unset($metadata['management_reconciliation'], $metadata['last_management_operation']);
        $subscription->update([
            'status' => $status,
            'management_state' => $managementState,
            'metadata' => $metadata,
        ]);
        return [
            'success' => true,
            'status' => $subscription->status,
            'management_state' => $subscription->management_state,
            'message' => $message,
        ];
    }

    protected function verifyProviderSubscription(Subscription $subscription, array $remote): array
    {
        $remoteId = $remote['id'] ?? null;
        $amount = $remote['amount'] ?? null;
        $currency = $remote['currency'] ?? null;
        $schedule = $remote['schedule'] ?? null;
        $interval = is_array($schedule) ? ($schedule['interval'] ?? null) : null;
        $intervalUnit = is_array($schedule) ? ($schedule['interval_unit'] ?? null) : null;
        $expectedUnit = $subscription->frequency === 'mingguan' ? 'week' : 'month';

        if ($remoteId !== $subscription->subscription_id) {
            return ['valid' => false, 'message' => 'ID Subscription provider tidak cocok.'];
        }
        if (!$this->midtrans->isExactAmountMatch($amount ?? '', (int) $subscription->nominal)) {
            return ['valid' => false, 'message' => 'Nominal provider tidak cocok.'];
        }
        if (strtoupper((string) $currency) !== 'IDR') {
            return ['valid' => false, 'message' => 'Currency provider tidak valid.'];
        }
        $intervalIsStrictOne = (
            (is_int($interval) && $interval === 1)
            || (is_string($interval) && $interval === '1')
        );
        if (!$intervalIsStrictOne) {
            return ['valid' => false, 'message' => 'Schedule provider tidak cocok.'];
        }
        if (!is_string($intervalUnit) || $intervalUnit !== $expectedUnit) {
            return ['valid' => false, 'message' => 'Schedule provider tidak cocok.'];
        }
        if (empty($remote['status'])) {
            return ['valid' => false, 'message' => 'Status provider tidak tersedia.'];
        }
        return ['valid' => true, 'message' => 'OK'];
    }

    protected function applyLocalState(Subscription $subscription, string $status, string $managementState, string $message): array
    {
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $subscription->update([
            'status' => $status,
            'management_state' => $managementState,
            'metadata' => $metadata,
        ]);
        return [
            'success' => true,
            'status' => $subscription->status,
            'management_state' => $subscription->management_state,
            'message' => $message,
        ];
    }

    protected function noOp(Subscription $subscription, string $status, string $managementState, string $message): array
    {
        return $this->applyLocalState($subscription, $status, $managementState, $message);
    }

    protected function setReconciliation(Subscription $subscription, string $operation): void
    {
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $metadata['last_management_operation'] = $operation;
        $metadata['management_reconciliation'] = 'provider_status_pending';
        $subscription->update(['metadata' => $metadata, 'management_state' => 'reconciliation']);
    }

    protected function failure(Subscription $subscription, string $message, int $httpStatus): array
    {
        $subscription->refresh();
        return [
            'success' => false,
            'status' => $subscription->status,
            'management_state' => $subscription->management_state,
            'message' => $message,
            'http_status' => $httpStatus,
        ];
    }
}
