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
        $guard = $this->acquireGuard($subscription);
        if (!$guard['acquired']) {
            return $this->guardOccupiedResponse($subscription);
        }

        $guardOwner = $guard['owner'];
        $subscription = $guard['snapshot'] ?? $subscription;

        try {
            $state = $subscription->management_state;
            if ($state !== 'reconciliation') {
                try {
                    $remote = $this->midtrans->getSubscription($subscription->subscription_id, (int) $subscription->id);
                } catch (\Throwable $e) {
                    $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                    if (!$snapshot) {
                        return $this->guardOwnershipLost($subscription);
                    }
                    return $this->snapshotResponse($snapshot, 'Status provider belum dapat diverifikasi.', 503);
                }

                $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$snapshot) {
                    return $this->guardOwnershipLost($subscription);
                }

                $verified = $this->verifyProviderSubscription($snapshot, $remote);
                if (!$verified['valid']) {
                    return $this->snapshotResponse($snapshot, $verified['message'], 422);
                }

                $providerStatus = $verified['normalized_status'];
                return $this->evaluateNonPendingStateConsistency($snapshot, $providerStatus);
            }

            try {
                $remote = $this->midtrans->getSubscription($subscription->subscription_id, (int) $subscription->id);
            } catch (\Throwable $e) {
                $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$snapshot) {
                    return $this->guardOwnershipLost($subscription);
                }
                return $this->snapshotResponse($snapshot, 'Reconciliation belum selesai; provider belum dapat diperiksa.', 202);
            }

            $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$snapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            $subscription = $snapshot;

            $verified = $this->verifyProviderSubscription($subscription, $remote);
            if (!$verified['valid']) {
                return $this->snapshotResponse($subscription, 'Reconciliation belum selesai karena data provider tidak dapat dipastikan.', 202);
            }

            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $lastOperation = $metadata['last_management_operation'] ?? null;
            $remoteStatus = $verified['normalized_status'];

            if ($lastOperation === 'pause' && $remoteStatus === 'inactive') {
                return $this->completeReconciliation(
                    $subscription,
                    $guardOwner,
                    'inactive',
                    'paused',
                    'Reconciliation selesai; Subscription telah dijeda.',
                    is_array($remote['schedule'] ?? null) ? $remote['schedule'] : null
                );
            }
            if ($lastOperation === 'resume' && $remoteStatus === 'active') {
                return $this->completeReconciliation(
                    $subscription,
                    $guardOwner,
                    'active',
                    'active',
                    'Reconciliation selesai; Subscription telah aktif.',
                    is_array($remote['schedule'] ?? null) ? $remote['schedule'] : null
                );
            }
            if ($lastOperation === 'cancel') {
                $explicitCancelled = in_array($remoteStatus, ['cancelled', 'canceled'], true);
                if ($explicitCancelled) {
                    return $this->completeReconciliation(
                        $subscription,
                        $guardOwner,
                        'inactive',
                        'cancelled',
                        'Reconciliation selesai; Subscription telah dibatalkan.'
                    );
                }
                if ($this->hasVerifiedCancellationEvidence($metadata) && $remoteStatus === 'inactive') {
                    return $this->confirmCancellation(
                        $subscription,
                        $guardOwner,
                        'Reconciliation selesai; pembatalan terverifikasi.'
                    );
                }
                return [
                    'success' => false,
                    'status' => $subscription->status,
                    'management_state' => $subscription->management_state,
                    'message' => 'Bukti pembatalan masih ambigu. Status inactive saja tidak membuktikan Cancel.',
                    'http_status' => 202,
                ];
            }

            return [
                'success' => false,
                'status' => $subscription->status,
                'management_state' => $subscription->management_state,
                'message' => 'Operasi reconciliation tidak dapat dipastikan.',
                'http_status' => 202,
            ];
        } catch (\Throwable $e) {
            $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$snapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            Log::warning('Subscription reconciliation failed unexpectedly', [
                'subscription_id' => $snapshot->subscription_id,
                'operation' => 'reconcile',
            ]);
            return $this->snapshotResponse(
                $snapshot,
                'Reconciliation tidak dapat diselesaikan karena gangguan internal atau provider.',
                503
            );
        } finally {
            $this->releaseGuard($subscription, $guardOwner);
        }
    }

    protected function manage(Subscription $subscription, string $operation): array
    {
        $guard = $this->acquireGuard($subscription);
        if (!$guard['acquired']) {
            return $this->guardOccupiedResponse($subscription);
        }

        $guardOwner = $guard['owner'];
        $subscription = $guard['snapshot'] ?? $subscription;

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
            if ($operation === 'pause' && $state === 'cancelled') {
                return $this->snapshotResponse($subscription, 'Subscription yang dibatalkan tidak dapat dijeda.', 422);
            }
            if ($operation === 'resume' && $state === 'cancelled') {
                return $this->snapshotResponse($subscription, 'Subscription yang dibatalkan tidak dapat dilanjutkan.', 422);
            }
            if ($operation === 'cancel' && $state === 'cancelled') {
                return $this->verifyCancelledNoOp($subscription, $guardOwner);
            }

            // Checkpoint 2 & 3: Initial GET + verifikasi kepemilikan guard pada return maupun exception
            try {
                $remoteBefore = $this->midtrans->getSubscription($subscription->subscription_id, (int) $subscription->id);
            } catch (\Throwable $e) {
                $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$snapshot) {
                    return $this->guardOwnershipLost($subscription);
                }
                Log::warning('Subscription management initial provider GET failed', [
                    'subscription_id' => $snapshot->subscription_id,
                    'operation' => $operation,
                ]);
                return $this->snapshotResponse($snapshot, 'Operasi tidak dapat diselesaikan karena provider.', 503);
            }

            $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$snapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            $subscription = $snapshot;

            $verified = $this->verifyProviderSubscription($subscription, $remoteBefore);
            if (!$verified['valid']) {
                return $this->snapshotResponse($subscription, $verified['message'], 422);
            }
            $beforeProviderStatus = $verified['normalized_status'];

            $beforeSchedule = is_array($remoteBefore['schedule'] ?? null) ? $remoteBefore['schedule'] : null;

            if ($operation === 'pause' && $state === 'paused') {
                if ($beforeProviderStatus === 'inactive') {
                    return $this->applyLocalState($subscription, $guardOwner, 'inactive', 'paused', 'Subscription sudah dijeda.', $beforeSchedule);
                }
                if ($beforeProviderStatus === 'active') {
                    return $this->setReconciliation(
                        $subscription,
                        $guardOwner,
                        'pause',
                        'Status lokal dijeda tetapi provider masih aktif. Pemeriksaan reconciliation diperlukan.'
                    );
                }
            }
            if ($operation === 'resume' && ($subscription->status === 'active' || $state === 'active')) {
                if ($beforeProviderStatus === 'active') {
                    return $this->applyLocalState($subscription, $guardOwner, 'active', 'active', 'Subscription sudah aktif.', $beforeSchedule);
                }
                if ($beforeProviderStatus === 'inactive') {
                    return $this->setReconciliation(
                        $subscription,
                        $guardOwner,
                        'resume',
                        'Resume tidak berhasil; provider masih inactive.'
                    );
                }
            }
            if ($operation === 'pause' && $beforeProviderStatus === 'inactive') {
                return $this->applyLocalState($subscription, $guardOwner, 'inactive', 'paused', 'Subscription sudah dinonaktifkan oleh provider.', $beforeSchedule);
            }
            if ($operation === 'resume' && $beforeProviderStatus === 'active') {
                return $this->applyLocalState($subscription, $guardOwner, 'active', 'active', 'Subscription sudah aktif di provider.', $beforeSchedule);
            }
            if ($operation === 'cancel' && in_array($beforeProviderStatus, ['cancelled', 'canceled'], true)) {
                return $this->confirmCancellation(
                    $subscription,
                    $guardOwner,
                    'Subscription sudah dibatalkan di provider.',
                    $beforeProviderStatus
                );
            }

            // Checkpoint 4: Verifikasi kepemilikan guard tepat sebelum mutasi provider keluar
            $preMutationSnapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$preMutationSnapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            $subscription = $preMutationSnapshot;

            $mutationSucceeded = false;
            $mutationTimedOut = false;
            $cancelResponseConfirmed = false;
            $cancelResponse = null;
            try {
                if ($operation === 'pause') {
                    $this->midtrans->disableSubscription($subscription->subscription_id, (int) $subscription->id);
                } elseif ($operation === 'resume') {
                    $this->midtrans->enableSubscription($subscription->subscription_id, (int) $subscription->id);
                } else {
                    $cancelResponse = $this->midtrans->cancelSubscription($subscription->subscription_id, (int) $subscription->id);
                    $cancelResponseConfirmed = is_array($cancelResponse)
                        && ($cancelResponse['_http_status'] ?? null) === 200
                        && array_key_exists('status_message', $cancelResponse)
                        && is_string($cancelResponse['status_message'])
                        && $cancelResponse['status_message'] === 'Subscription is updated.';
                }
                $mutationSucceeded = true;
            } catch (MidtransConnectionException $e) {
                // Checkpoint 6: Jika kepemilikan guard sudah berpindah saat timeout mutasi, langsung berhenti dengan 409
                $timeoutSnapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$timeoutSnapshot) {
                    Log::warning('Midtrans subscription mutation timed out after guard ownership loss', [
                        'subscription_id' => $subscription->subscription_id,
                        'operation' => $operation,
                    ]);
                    return $this->guardOwnershipLost($subscription);
                }
                $subscription = $timeoutSnapshot;
                $mutationTimedOut = true;
                Log::warning('Midtrans subscription mutation ambiguous', [
                    'subscription_id' => $subscription->subscription_id,
                    'operation' => $operation,
                ]);
            } catch (MidtransApiException $e) {
                // Checkpoint 7: Verifikasi kepemilikan guard sebelum menangani 5xx reconciliation atau error 503
                $apiErrSnapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$apiErrSnapshot) {
                    Log::warning('Midtrans subscription mutation rejected after guard ownership loss', [
                        'subscription_id' => $subscription->subscription_id,
                        'operation' => $operation,
                    ]);
                    return $this->guardOwnershipLost($subscription);
                }
                $subscription = $apiErrSnapshot;
                Log::warning('Midtrans subscription mutation rejected', [
                    'subscription_id' => $subscription->subscription_id,
                    'operation' => $operation,
                ]);
                if ($e->getStatusCode() >= 500 && $e->getStatusCode() <= 599) {
                    return $this->setReconciliation(
                        $subscription,
                        $guardOwner,
                        $operation,
                        'HTTP 5xx dapat berarti operasi sudah diterima. Lakukan reconciliation sebelum mutation lain.'
                    );
                }
                return $this->snapshotResponse($subscription, 'Operasi ditolak oleh provider atau tidak dapat diselesaikan.', 503);
            }

            if ($mutationSucceeded) {
                // Checkpoint 5: Verifikasi kepemilikan guard segera setelah mutasi provider kembali
                $postMutationSnapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
                if (!$postMutationSnapshot) {
                    Log::warning('Midtrans subscription mutation succeeded at provider after guard ownership loss', [
                        'subscription_id' => $subscription->subscription_id,
                        'operation' => $operation,
                    ]);
                    return $this->guardOwnershipLost($subscription);
                }
                $subscription = $postMutationSnapshot;

                // Checkpoint 8: Persist bukti ACK Cancel secara atomik di bawah lock kepemilikan guard sebelum GET lanjutan
                if ($operation === 'cancel' && $cancelResponseConfirmed) {
                    $ackUpdated = $this->mutateIfGuardOwned($subscription, $guardOwner, function (Subscription $locked): array {
                        $metadata = is_array($locked->metadata) ? $locked->metadata : [];
                        $confirmedAt = $metadata['cancellation']['confirmed_at'] ?? null;
                        $metadata['cancellation'] = [
                            'provider_confirmed' => true,
                            'provider_status' => 'pending_inactive_verification',
                            'confirmed_at' => (is_string($confirmedAt) && trim($confirmedAt) !== '')
                                ? $confirmedAt
                                : now()->toIso8601String(),
                        ];
                        $metadata['last_management_operation'] = 'cancel';
                        $metadata['management_reconciliation'] = 'provider_status_pending';

                        return [
                            'management_state' => 'reconciliation',
                            'metadata' => $metadata,
                        ];
                    });
                    if (!$ackUpdated) {
                        return $this->guardOwnershipLost($subscription);
                    }
                    $subscription = $ackUpdated;
                }
            }

            // Checkpoint 9 & 10: Post-mutation verification GET + cek kepemilikan guard pada return maupun exception
            try {
                $remoteAfter = $this->midtrans->getSubscription($subscription->subscription_id, (int) $subscription->id);
            } catch (\Throwable $e) {
                return $this->setReconciliation(
                    $subscription,
                    $guardOwner,
                    $operation,
                    'Operasi dikirim atau statusnya belum pasti. Lakukan pemeriksaan provider sebelum mencoba lagi.'
                );
            }

            $postGetSnapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$postGetSnapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            $subscription = $postGetSnapshot;

            $verifiedAfter = $this->verifyProviderSubscription($subscription, $remoteAfter);
            if (!$verifiedAfter['valid']) {
                return $this->setReconciliation(
                    $subscription,
                    $guardOwner,
                    $operation,
                    'Status provider sesudah operasi tidak dapat dipastikan. Pemeriksaan reconciliation diperlukan.'
                );
            }

            $remoteStatus = $verifiedAfter['normalized_status'];
            if ($mutationTimedOut) {
                return $this->setReconciliation(
                    $subscription,
                    $guardOwner,
                    $operation,
                    'Operasi belum dapat dipastikan. Status provider perlu diperiksa sebelum mencoba lagi.'
                );
            }

            // Checkpoint 11: Seluruh write final menggunakan mutateIfGuardOwned() di bawah lock kepemilikan
            $afterSchedule = is_array($remoteAfter['schedule'] ?? null) ? $remoteAfter['schedule'] : null;
            if ($operation === 'pause') {
                if ($remoteStatus === 'inactive') {
                    return $this->applyLocalState($subscription, $guardOwner, 'inactive', 'paused', 'Donasi rutin berhasil dijeda.', $afterSchedule);
                }
            } elseif ($operation === 'resume') {
                if ($remoteStatus === 'active') {
                    return $this->applyLocalState($subscription, $guardOwner, 'active', 'active', 'Donasi rutin berhasil dilanjutkan.', $afterSchedule);
                }
            } else {
                if (
                    $mutationSucceeded
                    && $cancelResponseConfirmed
                    && in_array($remoteStatus, ['inactive', 'cancelled', 'canceled'], true)
                ) {
                    return $this->confirmCancellation(
                        $subscription,
                        $guardOwner,
                        'Donasi rutin berhasil dibatalkan.',
                        $remoteStatus
                    );
                }
                if ($mutationSucceeded && $remoteStatus === 'inactive' && !$cancelResponseConfirmed) {
                    return $this->setReconciliation(
                        $subscription,
                        $guardOwner,
                        'cancel',
                        'Respons Cancel tidak lengkap. Pembatalan belum dapat dipastikan.'
                    );
                }
                return $this->setReconciliation(
                    $subscription,
                    $guardOwner,
                    'cancel',
                    'Pembatalan belum dapat dipastikan; lakukan pemeriksaan provider sebelum mencoba lagi.'
                );
            }

            return $this->setReconciliation(
                $subscription,
                $guardOwner,
                $operation,
                'Status provider tidak berubah seperti yang diharapkan; reconciliation diperlukan.'
            );
        } catch (\Throwable $e) {
            $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
            if (!$snapshot) {
                return $this->guardOwnershipLost($subscription);
            }
            Log::warning('Subscription management provider request failed', [
                'subscription_id' => $snapshot->subscription_id,
                'operation' => $operation,
            ]);
            return $this->snapshotResponse($snapshot, 'Operasi tidak dapat diselesaikan karena provider.', 503);
        } finally {
            $this->releaseGuard($subscription, $guardOwner);
        }
    }

    protected function acquireGuard(Subscription $subscription): array
    {
        $ownerToken = Str::uuid()->toString();
        $snapshot = DB::transaction(function () use ($subscription, $ownerToken): ?Subscription {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$locked) {
                return null;
            }

            $lease = $locked->operation_guard_at;
            $expired = $lease === null || $lease->lte(now()->subMinutes(5));
            if (!$expired && !empty($locked->operation_guard_owner)) {
                return null;
            }
            $locked->update([
                'operation_guard_at' => now(),
                'operation_guard_owner' => $ownerToken,
            ]);
            return $locked->fresh();
        });
        return [
            'acquired' => $snapshot !== null,
            'owner' => $ownerToken,
            'snapshot' => $snapshot,
        ];
    }

    protected function guardOccupiedResponse(Subscription $subscription): array
    {
        $latest = Subscription::whereKey($subscription->id)->first() ?? $subscription;
        return [
            'success' => false,
            'status' => $latest->status,
            'management_state' => $latest->management_state,
            'operation_in_progress' => true,
            'conflict_reason' => 'guard_occupied',
            'message' => 'Operasi subscription sedang berjalan atau baru saja dimulai.',
            'http_status' => 409,
        ];
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

    protected function verifyCancelledNoOp(Subscription $subscription, string $guardOwner): array
    {
        try {
            $remote = $this->midtrans->getSubscription($subscription->subscription_id, (int) $subscription->id);
        } catch (\Throwable $e) {
            return $this->setReconciliation(
                $subscription,
                $guardOwner,
                'cancel',
                'Pembatalan sebelumnya belum dapat diverifikasi ulang.'
            );
        }

        $snapshot = $this->verifyGuardOwnershipAndSnapshot($subscription, $guardOwner);
        if (!$snapshot) {
            return $this->guardOwnershipLost($subscription);
        }

        $verified = $this->verifyProviderSubscription($snapshot, $remote);
        if (!$verified['valid']) {
            return $this->snapshotResponse($snapshot, $verified['message'], 422);
        }
        $providerStatus = $verified['normalized_status'];
        if (in_array($providerStatus, ['cancelled', 'canceled'], true)) {
            return $this->completeReconciliation(
                $snapshot,
                $guardOwner,
                'inactive',
                'cancelled',
                'Subscription sudah dibatalkan; tidak ada pembatalan provider baru.'
            );
        }
        if ($providerStatus === 'inactive') {
            $outcome = $this->mutateIfGuardOwnedWithOutcome($snapshot, $guardOwner, function (Subscription $locked): array {
                $metadata = is_array($locked->metadata) ? $locked->metadata : [];
                if ($this->hasVerifiedCancellationEvidence($metadata)) {
                    unset($metadata['management_reconciliation'], $metadata['last_management_operation']);
                    return [
                        'attributes' => [
                            'status' => 'inactive',
                            'management_state' => 'cancelled',
                            'metadata' => $metadata,
                        ],
                        'response' => [
                            'success' => true,
                            'message' => 'Subscription sudah dibatalkan; tidak ada pembatalan provider baru.',
                            'http_status' => 200,
                        ],
                    ];
                }

                $metadata['last_management_operation'] = 'cancel';
                $metadata['management_reconciliation'] = 'provider_status_pending';

                return [
                    'attributes' => [
                        'management_state' => 'reconciliation',
                        'metadata' => $metadata,
                    ],
                    'response' => [
                        'success' => false,
                        'message' => 'Status inactive saja tidak membuktikan Cancel; pemeriksaan lanjutan diperlukan.',
                        'http_status' => 202,
                    ],
                ];
            });

            if (!$outcome) {
                return $this->guardOwnershipLost($snapshot);
            }

            /** @var Subscription $updated */
            $updated = $outcome['subscription'];
            $result = [
                'success' => $outcome['response']['success'],
                'status' => $updated->status,
                'management_state' => $updated->management_state,
                'message' => $outcome['response']['message'],
            ];
            if ($outcome['response']['http_status'] !== 200) {
                $result['http_status'] = $outcome['response']['http_status'];
            }
            return $result;
        }
        return $this->snapshotResponse($snapshot, 'Provider tidak memperlihatkan bukti pembatalan.', 422);
    }

    protected function confirmCancellation(
        Subscription $subscription,
        string $guardOwner,
        string $message,
        string $providerStatus = 'inactive'
    ): array {
        $updated = $this->mutateIfGuardOwned($subscription, $guardOwner, function (Subscription $locked) use ($providerStatus): array {
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            unset($metadata['management_reconciliation'], $metadata['last_management_operation']);
            $existingConfirmedAt = $metadata['cancellation']['confirmed_at'] ?? null;
            $metadata['cancellation'] = [
                'provider_confirmed' => true,
                'provider_status' => $providerStatus,
                'confirmed_at' => (is_string($existingConfirmedAt) && trim($existingConfirmedAt) !== '')
                    ? $existingConfirmedAt
                    : now()->toIso8601String(),
            ];

            return [
                'status' => 'inactive',
                'management_state' => 'cancelled',
                'metadata' => $metadata,
            ];
        });

        if (!$updated) {
            return $this->guardOwnershipLost($subscription);
        }

        return [
            'success' => true,
            'status' => $updated->status,
            'management_state' => $updated->management_state,
            'message' => $message,
        ];
    }

    protected function completeReconciliation(
        Subscription $subscription,
        string $guardOwner,
        string $status,
        string $managementState,
        string $message,
        ?array $remoteSchedule = null
    ): array {
        $updated = $this->mutateIfGuardOwned($subscription, $guardOwner, function (Subscription $locked) use ($status, $managementState, $remoteSchedule): array {
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            unset($metadata['management_reconciliation'], $metadata['last_management_operation']);

            $attributes = [
                'status' => $status,
                'management_state' => $managementState,
                'metadata' => $metadata,
            ];

            if ($managementState !== 'cancelled') {
                $existingScheduleMeta = is_array($metadata['schedule'] ?? null) ? $metadata['schedule'] : [];
                $existingCanonical = ($existingScheduleMeta['next_execution_source'] ?? null) === 'provider_confirmed'
                    ? $this->scheduleService()->parseProviderTimestamp($existingScheduleMeta['next_execution_utc'] ?? null)
                    : ['valid' => false];

                $rawScheduleTs = $remoteSchedule !== null ? ($remoteSchedule['next_execution_at'] ?? null) : null;
                $parsed = $this->scheduleService()->parseMidtransSubscriptionScheduleTimestamp(
                    $rawScheduleTs,
                    SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
                );
                if ($parsed['valid']) {
                    $attributes['next_execution_at'] = $parsed['utc'];
                    $metadata['schedule'] = $this->scheduleService()->buildScheduleMetadata($parsed);
                    $attributes['metadata'] = $metadata;
                } elseif ($existingCanonical['valid'] && $locked->next_execution_at !== null) {
                    $metadata['schedule'] = $this->scheduleService()->markPreservedScheduleStale($existingScheduleMeta, $parsed);
                    $attributes['metadata'] = $metadata;
                }
            }

            return $attributes;
        });

        if (!$updated) {
            return $this->guardOwnershipLost($subscription);
        }

        return [
            'success' => true,
            'status' => $updated->status,
            'management_state' => $updated->management_state,
            'message' => $message,
        ];
    }

    /**
     * Memverifikasi konsistensi status lokal, management_state, dan status provider
     * pada jalur non-pending tanpa melakukan sinkronisasi atau mutasi otomatis.
     */
    protected function evaluateNonPendingStateConsistency(Subscription $subscription, string $providerStatus): array
    {
        $localStatus = strtolower(trim((string) $subscription->status));
        $state = $subscription->management_state;
        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $hasVerifiedCancelEvidence = $this->hasVerifiedCancellationEvidence($metadata);
        $isExplicitProviderCancelled = in_array($providerStatus, ['cancelled', 'canceled'], true);

        if ($state === 'active') {
            if ($localStatus === 'active' && $providerStatus === 'active') {
                return [
                    'success' => true,
                    'status' => 'active',
                    'management_state' => 'active',
                    'message' => 'Tidak ada reconciliation pending. Status lokal dan provider konsisten aktif.',
                ];
            }
            return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
        }

        if ($state === 'paused') {
            if ($localStatus === 'inactive' && $providerStatus === 'inactive') {
                return [
                    'success' => true,
                    'status' => 'inactive',
                    'management_state' => 'paused',
                    'message' => 'Tidak ada reconciliation pending. Status lokal dan provider konsisten dijeda.',
                ];
            }
            return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
        }

        if ($state === 'cancelled') {
            if (!in_array($localStatus, ['inactive', 'cancelled'], true)) {
                return $this->snapshotResponse($subscription, 'Status lokal dan management state tidak konsisten.', 409);
            }
            if ($isExplicitProviderCancelled || ($providerStatus === 'inactive' && $hasVerifiedCancelEvidence)) {
                return [
                    'success' => true,
                    'status' => $subscription->status,
                    'management_state' => 'cancelled',
                    'message' => 'Tidak ada reconciliation pending. Pembatalan lokal dan provider terverifikasi konsisten.',
                ];
            }
            if ($providerStatus === 'inactive' && !$hasVerifiedCancelEvidence) {
                return $this->snapshotResponse($subscription, 'Status lokal dibatalkan tetapi bukti pembatalan terverifikasi tidak mencukupi.', 409);
            }
            return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
        }

        if ($state === null && $localStatus === 'active') {
            if ($providerStatus === 'active') {
                return [
                    'success' => true,
                    'status' => 'active',
                    'management_state' => null,
                    'message' => 'Tidak ada reconciliation pending. Status lokal dan provider konsisten aktif.',
                ];
            }
            return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
        }

        if ($state === null && $localStatus === 'inactive') {
            if ($providerStatus === 'inactive') {
                return [
                    'success' => true,
                    'status' => 'inactive',
                    'management_state' => null,
                    'message' => 'Tidak ada reconciliation pending. Status lokal dan provider sama-sama inactive tanpa management state.',
                ];
            }
            return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
        }

        return $this->snapshotResponse($subscription, 'Terdeteksi ketidaksesuaian antara status lokal dan status provider.', 409);
    }

    protected function hasVerifiedCancellationEvidence(array $metadata): bool
    {
        return isset($metadata['cancellation']['provider_confirmed'])
            && $metadata['cancellation']['provider_confirmed'] === true
            && in_array($metadata['cancellation']['provider_status'] ?? null, ['inactive', 'pending_inactive_verification', 'cancelled', 'canceled'], true)
            && is_string($metadata['cancellation']['confirmed_at'] ?? null)
            && trim($metadata['cancellation']['confirmed_at']) !== '';
    }

    /**
     * Memverifikasi kepemilikan guard dan mengambil snapshot dari row yang sama selama row lock aktif.
     * Snapshot hanya konsisten pada saat transaksi berlangsung; state tetap dapat berubah setelahnya.
     */
    protected function verifyGuardOwnershipAndSnapshot(Subscription $subscription, string $guardOwner): ?Subscription
    {
        return DB::transaction(function () use ($subscription, $guardOwner): ?Subscription {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$locked || $locked->operation_guard_owner !== $guardOwner) {
                return null;
            }
            return $locked;
        });
    }

    protected function snapshotResponse(Subscription $snapshot, string $message, int $httpStatus): array
    {
        return [
            'success' => false,
            'status' => $snapshot->status,
            'management_state' => $snapshot->management_state,
            'message' => $message,
            'http_status' => $httpStatus,
        ];
    }

    /**
     * Memutasi state Subscription hanya jika guard masih dimiliki oleh $guardOwner di bawah row lock,
     * dengan atribut/metadata dibangun langsung dari record terkunci terbaru.
     */
    protected function mutateIfGuardOwned(
        Subscription $subscription,
        string $guardOwner,
        callable $buildAttributes
    ): ?Subscription {
        return DB::transaction(function () use ($subscription, $guardOwner, $buildAttributes): ?Subscription {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$locked || $locked->operation_guard_owner !== $guardOwner) {
                return null;
            }

            $attributes = $buildAttributes($locked);
            $locked->update($attributes);

            return $locked->fresh();
        });
    }

    /**
     * Varian mutateIfGuardOwned yang mengembalikan record terbarui beserta metadata keputusan lokal.
     *
     * @return array{subscription: Subscription, response: array}|null
     */
    protected function mutateIfGuardOwnedWithOutcome(
        Subscription $subscription,
        string $guardOwner,
        callable $buildOutcome
    ): ?array {
        return DB::transaction(function () use ($subscription, $guardOwner, $buildOutcome): ?array {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();
            if (!$locked || $locked->operation_guard_owner !== $guardOwner) {
                return null;
            }

            $outcome = $buildOutcome($locked);
            $locked->update($outcome['attributes']);

            return [
                'subscription' => $locked->fresh(),
                'response' => $outcome['response'],
            ];
        });
    }

    protected function guardOwnershipLost(Subscription $subscription): array
    {
        $latest = Subscription::whereKey($subscription->id)->first() ?? $subscription;
        return [
            'success' => false,
            'status' => $latest->status,
            'management_state' => $latest->management_state,
            'operation_in_progress' => $latest->isOperationInProgress(),
            'conflict_reason' => 'guard_ownership_lost',
            'message' => 'Kepemilikan operasi subscription telah berpindah. Perubahan status dibatalkan untuk mencegah konflik.',
            'http_status' => 409,
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
        if (!$this->midtrans->isExactAmountMatch($amount, $subscription->nominal)) {
            return ['valid' => false, 'message' => 'Nominal provider tidak cocok.'];
        }
        if (!is_string($currency) || strtoupper(trim($currency)) !== 'IDR') {
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
        $rawStatus = $remote['status'] ?? null;
        if (!is_string($rawStatus) || trim($rawStatus) === '') {
            return ['valid' => false, 'message' => 'Status provider tidak valid atau tidak tersedia.'];
        }
        $normalizedStatus = strtolower(trim($rawStatus));
        if (!in_array($normalizedStatus, ['active', 'inactive', 'cancelled', 'canceled'], true)) {
            return ['valid' => false, 'message' => 'Status provider tidak didukung.'];
        }
        return ['valid' => true, 'message' => 'OK', 'normalized_status' => $normalizedStatus];
    }

    protected function applyLocalState(
        Subscription $subscription,
        string $guardOwner,
        string $status,
        string $managementState,
        string $message,
        ?array $remoteSchedule = null
    ): array {
        $updated = $this->mutateIfGuardOwned($subscription, $guardOwner, function (Subscription $locked) use ($status, $managementState, $remoteSchedule): array {
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];

            $attributes = [
                'status' => $status,
                'management_state' => $managementState,
                'metadata' => $metadata,
            ];

            if ($managementState !== 'cancelled') {
                $existingScheduleMeta = is_array($metadata['schedule'] ?? null) ? $metadata['schedule'] : [];
                $existingCanonical = ($existingScheduleMeta['next_execution_source'] ?? null) === 'provider_confirmed'
                    ? $this->scheduleService()->parseProviderTimestamp($existingScheduleMeta['next_execution_utc'] ?? null)
                    : ['valid' => false];

                $rawScheduleTs = $remoteSchedule !== null ? ($remoteSchedule['next_execution_at'] ?? null) : null;
                $parsed = $this->scheduleService()->parseMidtransSubscriptionScheduleTimestamp(
                    $rawScheduleTs,
                    SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
                );
                if ($parsed['valid']) {
                    $attributes['next_execution_at'] = $parsed['utc'];
                    $metadata['schedule'] = $this->scheduleService()->buildScheduleMetadata($parsed);
                    $attributes['metadata'] = $metadata;
                } elseif ($existingCanonical['valid'] && $locked->next_execution_at !== null) {
                    $metadata['schedule'] = $this->scheduleService()->markPreservedScheduleStale($existingScheduleMeta, $parsed);
                    $attributes['metadata'] = $metadata;
                }
            }

            return $attributes;
        });

        if (!$updated) {
            return $this->guardOwnershipLost($subscription);
        }

        return [
            'success' => true,
            'status' => $updated->status,
            'management_state' => $updated->management_state,
            'message' => $message,
        ];
    }

    protected function scheduleService(): SubscriptionScheduleService
    {
        return app(SubscriptionScheduleService::class);
    }

    protected function setReconciliation(
        Subscription $subscription,
        string $guardOwner,
        string $operation,
        string $message = 'Status provider belum dapat dipastikan; reconciliation diperlukan.'
    ): array {
        $updated = $this->mutateIfGuardOwned($subscription, $guardOwner, function (Subscription $locked) use ($operation): array {
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['last_management_operation'] = $operation;
            $metadata['management_reconciliation'] = 'provider_status_pending';

            return [
                'metadata' => $metadata,
                'management_state' => 'reconciliation',
            ];
        });

        if (!$updated) {
            return $this->guardOwnershipLost($subscription);
        }

        return [
            'success' => false,
            'status' => $updated->status,
            'management_state' => $updated->management_state,
            'message' => $message,
            'http_status' => 202,
        ];
    }
}
