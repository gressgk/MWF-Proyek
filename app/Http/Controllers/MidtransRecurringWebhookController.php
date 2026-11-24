<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Services\CampaignProgressService;
use App\Services\MidtransPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MidtransRecurringWebhookController — Menerima dan memproses notifikasi siklus pembayaran
 * berulang dari Midtrans Subscription API dengan pengamanan ketat (E4.2 Hardening).
 *
 * ENDPOINT: POST /midtrans/webhook/subscription  (dikecualikan dari CSRF via bootstrap/app.php)
 *
 * PRINSIP PENGAMANAN E4.2:
 *   1. Seluruh perubahan status Subscription dan pencatatan Donation wajib berdasarkan bukti terautentikasi.
 *   2. Event subscription.inactive tanpa signature wajib diverifikasi via provider Get Subscription API.
 *   3. Untuk settlement tanpa signature, wajib rekonsiliasi aktif via Status API (status_code 200, settlement/capture accept,
 *      gross_amount exact match, currency IDR, serta ketiadaan konflik kepemilikan order_id).
 *   4. Keberadaan Subscription TIDAK PERNAH digunakan sebagai bukti transaksi telah dibayar.
 *   5. capture tanpa fraud_status='accept' eksplisit DITOLAK dari status pembayaran berhasil.
 *   6. gross_amount wajib ada dan divalidasi tanpa toleransi string kosong.
 *   7. Transaksi database atomik dengan row lock untuk mencegah race condition concurrent webhook.
 */
class MidtransRecurringWebhookController extends Controller
{
    public function handle(
        Request $request,
        CampaignProgressService $progressService,
        MidtransPaymentService $midtransService
    ): \Illuminate\Http\JsonResponse {
        $payload = $request->all();

        $eventName = $payload['event_name'] ?? null;
        $nestedSubscription = $payload['subscription'] ?? null;
        if ($eventName === 'subscription.inactive'
            && is_array($nestedSubscription)
            && !empty($nestedSubscription['id'])
        ) {
            $subscriptionId = (string) $nestedSubscription['id'];
            $subscription = Subscription::where('subscription_id', $subscriptionId)->first();
            if (!$subscription) {
                return response()->json(['message' => 'Subscription not found'], 200);
            }
            if ($subscription->management_state === 'cancelled') {
                return response()->json(['message' => 'OK'], 200);
            }
            try {
                $remoteSub = $midtransService->getSubscription($subscriptionId);
                $remoteSubStatus = strtolower((string) ($remoteSub['status'] ?? ''));
                if (!in_array($remoteSubStatus, ['inactive', 'cancelled', 'disabled', 'expired'], true)) {
                    return response()->json(['message' => 'Unverified subscription event'], 403);
                }
                $subscription->update(['status' => 'inactive']);
                return response()->json(['message' => 'OK'], 200);
            } catch (\Throwable $e) {
                Log::warning('Midtrans recurring webhook: inactive provider verification failed', [
                    'subscription_id' => $subscriptionId,
                ]);
                return response()->json(['message' => 'Service unavailable'], 503);
            }
        }
        $hasRecurringTransaction = array_key_exists('transaction', $payload);
        $nestedCreation = $eventName === 'subscription.create'
            && isset($payload['subscription'])
            && is_array($payload['subscription'])
            && !$hasRecurringTransaction;
        $isFlatCreationNotification = empty($eventName)
            && !$hasRecurringTransaction
            && !isset($payload['subscription']);

        if ($nestedCreation || $isFlatCreationNotification) {
            $creationPayload = $nestedCreation
                ? $payload['subscription']
                : $payload;
            $creationRequiredFields = $nestedCreation
                ? ['id', 'status', 'amount', 'currency', 'payment_type', 'schedule']
                : ['id', 'name', 'status', 'amount', 'currency', 'payment_type', 'schedule'];
            $missingFields = array_values(array_filter(
                $creationRequiredFields,
                static fn (string $field): bool => !array_key_exists($field, $creationPayload)
            ));

            if (!empty($missingFields)
                || !is_string($creationPayload['id'] ?? null)
                || trim((string) ($creationPayload['id'] ?? '')) === ''
                || !is_array($creationPayload['schedule'] ?? null)
                || empty($creationPayload['currency'] ?? null)
                || empty($creationPayload['payment_type'] ?? null)
            ) {
                Log::warning('Midtrans subscription creation webhook: struktur payload tidak lengkap', [
                    'missing_fields' => $missingFields,
                ]);
                return response()->json(['message' => 'Invalid payload'], 400);
            }

            $subscriptionId = trim((string) $creationPayload['id']);
            $localSubscription = Subscription::where('subscription_id', $subscriptionId)->first();

            if (!$localSubscription) {
                Log::info('Midtrans subscription creation webhook acknowledged for unknown local subscription', [
                    'subscription_id' => $subscriptionId,
                ]);
            } else {
                Log::info('Midtrans subscription creation webhook acknowledged', [
                    'subscription_id' => $subscriptionId,
                    'provider_status' => is_string($creationPayload['status'] ?? null)
                        ? $creationPayload['status']
                        : null,
                ]);
            }

            // ACK-only: notification creation tidak membuktikan pembayaran,
            // tidak membuat Subscription lokal, Donation, maupun progress Campaign.
            return response()->json(['message' => 'OK'], 200);
        }

        // 1. Ekstrak data dari struktur resmi Midtrans Subscription API
        $subData   = $payload['subscription'] ?? [];
        $txData    = $payload['transaction'] ?? [];

        // Resolusi ID Subscription dari provider
        $subscriptionId = $subData['id'] ?? $payload['subscription_id'] ?? null;

        // Resolusi detail transaksi siklus:
        // Jika order_id tidak ada, gunakan transaction_id sebagai identitas charge siklus
        $chargeOrderId     = $txData['order_id'] ?? $txData['transaction_id'] ?? $payload['order_id'] ?? null;
        $transactionId     = $txData['transaction_id'] ?? $payload['transaction_id'] ?? null;
        $statusCode        = (string) ($txData['status_code'] ?? $payload['status_code'] ?? '');
        $transactionStatus = $txData['transaction_status'] ?? $payload['transaction_status'] ?? null;
        $fraudStatus       = $txData['fraud_status'] ?? $payload['fraud_status'] ?? null;
        
        // Nominal: ambil dari transaction.gross_amount, fallback ke subscription.amount
        $grossAmount       = $txData['gross_amount'] ?? $subData['amount'] ?? $payload['gross_amount'] ?? null;
        $signatureKey      = $txData['signature_key'] ?? $payload['signature_key'] ?? null;

        // 2. Validasi kelengkapan payload minimal
        if (empty($subscriptionId) || (empty($chargeOrderId) && empty($transactionId)) || empty($statusCode)) {
            Log::warning('Midtrans recurring webhook: payload tidak lengkap', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $chargeOrderId,
                'status_code'     => $statusCode,
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        if (empty($chargeOrderId)) {
            $chargeOrderId = $transactionId;
        }

        // 3. Verifikasi signature SHA512 jika signature_key disertakan
        $hasValidSignature = false;
        if (!empty($signatureKey)) {
            $signingSecret = config('services.midtrans.signature_secret_key', '');
            if (empty($signingSecret)) {
                Log::error('Midtrans recurring webhook: signature secret key tidak dikonfigurasi');
                return response()->json(['message' => 'Service unavailable'], 503);
            }

            $expectedSignature = hash('sha512', $chargeOrderId . $statusCode . $grossAmount . $signingSecret);
            if (!hash_equals($expectedSignature, $signatureKey)) {
                Log::warning('Midtrans recurring webhook: signature tidak valid', [
                    'charge_order_id' => $chargeOrderId,
                ]);
                return response()->json(['message' => 'Invalid signature'], 403);
            }
            $hasValidSignature = true;
        }

        // 4. Resolusi Subscription berdasarkan subscription.id provider
        $subscription = Subscription::where('subscription_id', $subscriptionId)->first();

        if (!$subscription) {
            Log::warning('Midtrans recurring webhook: subscription tidak ditemukan pada database lokal', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $chargeOrderId,
            ]);
            // Kembalikan 200 agar provider tidak terus retry untuk subscription yang bukan milik WeCare
            return response()->json(['message' => 'Subscription not found'], 200);
        }

        // 5. Penanganan event penonaktifan langganan (non-charge event)
        if ($eventName === 'subscription.inactive') {
            if ($subscription->management_state === 'cancelled') {
                Log::info('Midtrans recurring webhook: late inactive notification ignored for cancelled subscription', [
                    'subscription_id' => $subscriptionId,
                ]);
                return response()->json(['message' => 'OK'], 200);
            }
            // E4 Sandbox Preflight: Verifikasi status provider meskipun notification memiliki signature valid,
            // karena status subscription tidak otomatis terikat oleh signature transaksi.
            try {
                $remoteSub = $midtransService->getSubscription($subscriptionId);
                $remoteSubStatus = $remoteSub['status'] ?? null;
                if (!in_array($remoteSubStatus, ['inactive', 'cancelled', 'disabled', 'expired'], true)) {
                    Log::warning('Midtrans recurring webhook: Inactive notification ditolak karena status provider masih aktif', [
                        'subscription_id'   => $subscriptionId,
                        'remote_sub_status' => $remoteSubStatus,
                    ]);
                    return response()->json(['message' => 'Unverified subscription event'], 403);
                }
            } catch (\Throwable $e) {
                Log::error('Midtrans recurring webhook: Gagal verifikasi status subscription ke provider', [
                    'subscription_id' => $subscriptionId,
                    'error'           => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Service unavailable'], 503);
            }

            $subscription->update(['status' => 'inactive']);
            Log::info('Midtrans recurring webhook: langganan berhasil dinonaktifkan terverifikasi', [
                'subscription_id' => $subscriptionId,
            ]);
            return response()->json(['message' => 'OK'], 200);
        }

        // 5b. Cek konflik order_id: pastikan order_id tidak terdaftar pada donasi lain di luar subscription ini
        $conflictingDonation = Donation::where('order_id', $chargeOrderId)
            ->where(function ($query) use ($subscription) {
                $query->whereNull('subscription_id')
                      ->orWhere('subscription_id', '!=', $subscription->subscription_id);
            })
            ->first();

        if ($conflictingDonation) {
            Log::error('Midtrans recurring webhook: Konflik order_id terdeteksi milik donasi lain', [
                'charge_order_id'        => $chargeOrderId,
                'existing_donation_id'   => $conflictingDonation->id,
                'target_subscription_id' => $subscription->subscription_id,
            ]);
            return response()->json(['message' => 'Order ID conflict with another donation'], 409);
        }

        // 6. E4.3.1: Evaluasi status transaksi dan penanganan Capture
        // Jika capture tapi fraud_status eksplisit challenge atau deny -> tolak langsung tanpa query
        if ($transactionStatus === 'capture' && in_array($fraudStatus, ['challenge', 'deny'], true)) {
            Log::info('Midtrans recurring webhook: capture ditolak karena fraud_status challenge/deny', [
                'charge_order_id' => $chargeOrderId,
                'fraud_status'    => $fraudStatus,
            ]);
            return response()->json(['message' => 'OK'], 200);
        }

        // 7. E4.2 Hardening: gross_amount WAJIB ada dan valid (tidak boleh kosong atau terlewati)
        if (empty($grossAmount)) {
            Log::error('Midtrans recurring webhook: gross_amount tidak ada dalam payload transaksi maupun subscription', [
                'charge_order_id' => $chargeOrderId,
            ]);
            return response()->json(['message' => 'Amount is required'], 422);
        }

        if (!$midtransService->isExactAmountMatch($grossAmount, (int) $subscription->nominal)) {
            Log::error('Midtrans recurring webhook: nominal siklus tidak cocok dengan nominal subscription', [
                'charge_order_id'      => $chargeOrderId,
                'subscription_id'      => $subscription->subscription_id,
                'subscription_nominal' => $subscription->nominal,
                'payload_gross_amount' => $grossAmount,
            ]);
            return response()->json(['message' => 'Amount mismatch'], 422);
        }

        // 8. E4.3.1: Rekonsiliasi aktif provider jika signature tidak ada, capture tanpa fraud_status,
        // atau untuk memastikan validitas status, nominal, dan mata uang transaksi
        $requiresStatusReconciliation = !$hasValidSignature || ($transactionStatus === 'capture' && empty($fraudStatus));

        if ($requiresStatusReconciliation) {
            try {
                $remoteStatus   = $midtransService->checkTransactionStatus($chargeOrderId);
                $remoteTxStatus = $remoteStatus['transaction_status'] ?? null;
                $remoteFraud    = $remoteStatus['fraud_status'] ?? null;
                $remoteAmount   = $remoteStatus['gross_amount'] ?? null;
                $remoteCurrency = $remoteStatus['currency'] ?? null;
                $remoteCode     = (string) ($remoteStatus['status_code'] ?? '');

                // Validasi status pembayaran provider
                $remoteIsSuccess = ($remoteTxStatus === 'settlement') || ($remoteTxStatus === 'capture' && $remoteFraud === 'accept');
                if (!$remoteIsSuccess || $remoteCode !== '200') {
                    Log::warning('Midtrans recurring webhook: Rekonsiliasi provider gagal - status bukan settlement/capture-accept', [
                        'charge_order_id' => $chargeOrderId,
                        'remote_status'   => $remoteTxStatus,
                        'remote_fraud'    => $remoteFraud,
                        'remote_code'     => $remoteCode,
                    ]);
                    if ($transactionStatus === 'capture' && empty($fraudStatus) && $hasValidSignature) {
                        return response()->json(['message' => 'OK'], 200);
                    }
                    return response()->json(['message' => 'Provider verification failed'], 403);
                }

                // Validasi mata uang harus IDR
                if (!empty($remoteCurrency) && strtoupper($remoteCurrency) !== 'IDR') {
                    Log::warning('Midtrans recurring webhook: Mata uang provider bukan IDR', [
                        'charge_order_id' => $chargeOrderId,
                        'currency'        => $remoteCurrency,
                    ]);
                    return response()->json(['message' => 'Invalid currency'], 422);
                }

                // Validasi nominal provider harus cocok eksak
                if (empty($remoteAmount) || !$midtransService->isExactAmountMatch($remoteAmount, (int) $subscription->nominal)) {
                    Log::warning('Midtrans recurring webhook: Nominal provider tidak cocok dengan subscription', [
                        'charge_order_id' => $chargeOrderId,
                        'remote_amount'   => $remoteAmount,
                        'sub_nominal'     => $subscription->nominal,
                    ]);
                    return response()->json(['message' => 'Provider amount mismatch'], 422);
                }

                $transactionStatus = $remoteTxStatus;
                $fraudStatus = $remoteFraud;
            } catch (\Throwable $e) {
                Log::error('Midtrans recurring webhook: Gagal menghubungi Status API provider', [
                    'charge_order_id' => $chargeOrderId,
                    'error'           => $e->getMessage(),
                ]);
                if ($transactionStatus === 'capture' && empty($fraudStatus) && $hasValidSignature) {
                    return response()->json(['message' => 'OK'], 200);
                }
                return response()->json(['message' => 'Provider verification failed'], 403);
            }
        }

        // 8b. Evaluasi status pembayaran: Hanya settlement atau capture (accept) yang diproses lebih lanjut
        $isSettlement = ($transactionStatus === 'settlement');
        $isCaptureAccept = ($transactionStatus === 'capture' && $fraudStatus === 'accept');

        if (!$isSettlement && !$isCaptureAccept) {
            Log::info('Midtrans recurring webhook: siklus gagal atau belum settlement', [
                'charge_order_id'    => $chargeOrderId,
                'subscription_id'    => $subscription->subscription_id,
                'status_code'        => $statusCode,
                'transaction_status' => $transactionStatus,
                'fraud_status'       => $fraudStatus,
            ]);
            return response()->json(['message' => 'OK'], 200);
        }

        // 8c. E4 Sandbox Preflight: Buktikan Kepemilikan Transaksi terhadap Subscription (Linkage Verification)
        // Semua successful recurring charge (termasuk signed settlement) WAJIB membuktikan bahwa
        // transaction_id benar-benar terkait dengan subscription yang diklaim di provider.
        try {
            $remoteSub = $midtransService->getSubscription($subscriptionId);
        } catch (\Throwable $e) {
            Log::error('Midtrans recurring webhook: Get Subscription API gagal saat verifikasi kepemilikan', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $chargeOrderId,
                'error'           => $e->getMessage(),
            ]);
            // FAIL CLOSED: Jangan abaikan kegagalan Get Subscription API!
            return response()->json(['message' => 'Provider verification failed'], 403);
        }

        $isOwnershipProven = false;

        // A. Cek daftar transaction_ids resmi dari provider
        if (isset($remoteSub['transaction_ids']) && is_array($remoteSub['transaction_ids']) && !empty($remoteSub['transaction_ids'])) {
            if (in_array($transactionId, $remoteSub['transaction_ids'], true) ||
                in_array($chargeOrderId, $remoteSub['transaction_ids'], true)) {
                $isOwnershipProven = true;
            }
        } elseif (isset($remoteSub['transactions']) && is_array($remoteSub['transactions']) && !empty($remoteSub['transactions'])) {
            foreach ($remoteSub['transactions'] as $tx) {
                $txObjId = is_array($tx) ? ($tx['id'] ?? $tx['transaction_id'] ?? $tx['order_id'] ?? null) : $tx;
                if ($txObjId === $transactionId || $txObjId === $chargeOrderId) {
                    $isOwnershipProven = true;
                    break;
                }
            }
        }

        // FAIL CLOSED jika kepemilikan transaksi terhadap subscription tidak dapat dibuktikan
        // (E4 Sandbox Preflight: jangan gunakan kecocokan prefix nama subscription sebagai satu-satunya bukti)
        if (!$isOwnershipProven) {
            Log::warning('Midtrans recurring webhook: Bukti linkage transaksi terhadap subscription tidak ditemukan di provider', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $chargeOrderId,
                'transaction_id'  => $transactionId,
            ]);
            return response()->json(['message' => 'Transaction ID not linked to subscription'], 403);
        }

        // 9. Idempotency check awal sebelum transaksi
        $existingDonation = Donation::where('order_id', $chargeOrderId)->first();
        if ($existingDonation && $existingDonation->status === 'terverifikasi') {
            if ($existingDonation->subscription_id === $subscription->subscription_id) {
                Log::info('Midtrans recurring webhook: siklus sudah terverifikasi sebelumnya (idempotent)', [
                    'charge_order_id' => $chargeOrderId,
                ]);
                return response()->json(['message' => 'OK'], 200);
            } else {
                Log::error('Midtrans recurring webhook: order_id konflik dengan donasi non-langganan', [
                    'charge_order_id' => $chargeOrderId,
                ]);
                return response()->json(['message' => 'Order ID conflict'], 409);
            }
        }

        // 10. Catat donasi siklus baru secara atomik dengan row lock untuk mencegah concurrent race condition
        try {
            DB::transaction(function () use (
                $subscription, $chargeOrderId, $grossAmount,
                $transactionStatus, $fraudStatus,
                $progressService
            ) {
                // Lock dan periksa kembali di dalam database transaction
                $lockedExisting = Donation::where('order_id', $chargeOrderId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting) {
                    if ($lockedExisting->status === 'terverifikasi' && $lockedExisting->subscription_id === $subscription->subscription_id) {
                        return; // Sudah diproses oleh request concurrent paralel
                    }
                    if ($lockedExisting->subscription_id !== $subscription->subscription_id) {
                        throw new \DomainException("Order ID conflict: already used by another donation record.");
                    }
                }

                // Cek konflik dengan donasi campaign / user lain
                $isUsedByOther = Donation::where('order_id', $chargeOrderId)
                    ->where(function ($q) use ($subscription) {
                        $q->whereNull('subscription_id')
                          ->orWhere('subscription_id', '!=', $subscription->subscription_id);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($isUsedByOther) {
                    throw new \DomainException("Order ID conflict: used by another donation.");
                }

                $donation = Donation::create([
                    'campaign_id'                => $subscription->campaign_id,
                    'user_id'                    => $subscription->user_id,
                    'jenis'                      => 'uang',
                    'is_anonim'                  => $subscription->is_anonim,
                    'nominal'                    => (int) $subscription->nominal,
                    'is_recurring'               => true,
                    'frequency'                  => $subscription->frequency,
                    'order_id'                   => $chargeOrderId,
                    'subscription_id'            => $subscription->subscription_id,
                    'status'                     => 'terverifikasi',
                    'payment_status_detail'      => 'settled',
                    'webhook_processed_at'       => now(),
                    'webhook_transaction_status' => $transactionStatus,
                    'webhook_fraud_status'       => $fraudStatus ?? 'accept',
                    'idempotency_key'            => Str::uuid()->toString(),
                    'tanggal_diterima'           => now(),
                ]);

                // Perbarui progress Campaign (terkumpul_nominal)
                $campaign = Campaign::find($subscription->campaign_id);
                if ($campaign) {
                    $progressService->recalculate($campaign);
                }

                Log::info('Midtrans recurring webhook: siklus berhasil dicatat', [
                    'charge_order_id' => $chargeOrderId,
                    'donation_id'     => $donation->id,
                    'subscription_id' => $subscription->subscription_id,
                    'nominal'         => $donation->nominal,
                ]);
            });
        } catch (\DomainException $e) {
            Log::warning('Midtrans recurring webhook: ' . $e->getMessage(), [
                'charge_order_id' => $chargeOrderId,
            ]);
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            Log::error('Midtrans recurring webhook: gagal mencatat donasi siklus', [
                'charge_order_id' => $chargeOrderId,
                'error'           => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Internal error'], 500);
        }

        return response()->json(['message' => 'OK'], 200);
    }
}
