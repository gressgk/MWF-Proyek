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
 *   2. Event subscription.update_status dan legacy subscription.inactive diproses sebagai Verified ACK-only
 *      melalui provider Get Subscription API tanpa mutasi state bisnis lokal.
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
        if ($eventName === 'subscription.update_status') {
            return $this->handleStatusUpdateNotification($payload, $midtransService);
        }

        if ($eventName === 'subscription.inactive') {
            return $this->handleLegacyInactiveNotification($payload, $midtransService);
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
        if (array_key_exists('subscription', $payload) && !is_array($payload['subscription'])) {
            Log::warning('Midtrans recurring webhook: subscription bukan array/object');
            return response()->json(['message' => 'Invalid payload'], 400);
        }
        if (array_key_exists('transaction', $payload) && !is_array($payload['transaction'])) {
            Log::warning('Midtrans recurring webhook: transaction bukan array/object');
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $subData   = $payload['subscription'] ?? [];
        $txData    = $payload['transaction'] ?? [];

        // Resolusi ID Subscription dari provider
        $subscriptionId = $subData['id'] ?? $payload['subscription_id'] ?? null;

        // Resolusi detail transaksi siklus:
        // Simpan order_id eksplisit terpisah dari fallback transaction_id untuk mengikat verifikasi Status API (TZ0.1)
        $payloadOrderId    = $txData['order_id'] ?? $payload['order_id'] ?? null;
        $chargeOrderId     = $payloadOrderId ?? $txData['transaction_id'] ?? null;
        $transactionId     = $txData['transaction_id'] ?? $payload['transaction_id'] ?? null;
        $rawStatusCode     = $txData['status_code'] ?? $payload['status_code'] ?? null;
        $transactionStatus = $txData['transaction_status'] ?? $payload['transaction_status'] ?? null;
        $fraudStatus       = $txData['fraud_status'] ?? $payload['fraud_status'] ?? null;

        // Nominal: ambil sumber utama tanpa menimpa explicit null/malformed dengan fallback (C2)
        $amountExtraction  = $this->extractRecurringChargeAmounts($payload, $subData, $txData);
        $grossAmount       = $amountExtraction['selected_raw'];

        // 2. Validasi kelengkapan dan tipe payload minimal
        $validSubscriptionId = is_string($subscriptionId) && trim($subscriptionId) !== '';
        $validChargeOrderId = $chargeOrderId === null || (is_string($chargeOrderId) && trim($chargeOrderId) !== '');
        $validTransactionId = $transactionId === null || (is_string($transactionId) && trim($transactionId) !== '');
        $hasOrderOrTxId = (is_string($chargeOrderId) && trim($chargeOrderId) !== '')
            || (is_string($transactionId) && trim($transactionId) !== '');
        $validStatusCode = (is_string($rawStatusCode) && $rawStatusCode !== '') || is_int($rawStatusCode);
        $validTxStatus = $transactionStatus === null || is_string($transactionStatus);
        $validFraudStatus = $fraudStatus === null || is_string($fraudStatus);

        if (
            !$validSubscriptionId
            || !$validChargeOrderId
            || !$validTransactionId
            || !$hasOrderOrTxId
            || !$validStatusCode
            || !$validTxStatus
            || !$validFraudStatus
        ) {
            Log::warning('Midtrans recurring webhook: payload tidak lengkap atau tipe tidak valid', [
                'subscription_id' => is_string($subscriptionId) ? $subscriptionId : null,
                'charge_order_id' => is_string($chargeOrderId) ? $chargeOrderId : null,
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $statusCode = (string) $rawStatusCode;
        if (empty($chargeOrderId)) {
            $chargeOrderId = $transactionId;
        }

        // 3. Verifikasi signature SHA512 jika field signature_key disertakan (representasi mentah tetap utuh)
        $hasTxSignature = array_key_exists('signature_key', $txData);
        $hasRootSignature = array_key_exists('signature_key', $payload);
        if ($hasTxSignature && $hasRootSignature && $txData['signature_key'] !== $payload['signature_key']) {
            Log::warning('Midtrans recurring webhook: signature_key konflik antara root dan transaction');
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $hasSignatureField = $hasTxSignature || $hasRootSignature;
        $signatureKey = $hasTxSignature ? $txData['signature_key'] : ($hasRootSignature ? $payload['signature_key'] : null);

        $hasValidSignature = false;
        if ($hasSignatureField) {
            if (!is_string($signatureKey) || trim($signatureKey) === '') {
                Log::warning('Midtrans recurring webhook: signature tidak valid', [
                    'charge_order_id' => $chargeOrderId,
                ]);
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            $signingSecret = config('services.midtrans.signature_secret_key', '');
            if (empty($signingSecret)) {
                Log::error('Midtrans recurring webhook: signature secret key tidak dikonfigurasi');
                return response()->json(['message' => 'Service unavailable'], 503);
            }

            $validSigGrossAmount = (is_string($grossAmount) && $grossAmount !== '') || is_int($grossAmount);
            if (!$validSigGrossAmount) {
                Log::warning('Midtrans recurring webhook: input gross_amount signature tidak valid', [
                    'charge_order_id' => $chargeOrderId,
                ]);
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            $expectedSignature = hash('sha512', $chargeOrderId . $statusCode . (string) $grossAmount . $signingSecret);
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

        // 7. E4.2 + E5.1.8C.1 (C2) Hardening: seluruh field nominal yang hadir eksplisit wajib valid & konsisten
        if (!$amountExtraction['has_selected']) {
            Log::error('Midtrans recurring webhook: gross_amount tidak ada dalam payload transaksi maupun subscription', [
                'charge_order_id' => $chargeOrderId,
            ]);
            return response()->json(['message' => 'Amount is required'], 422);
        }

        $normalizedPresent = [];
        foreach ($amountExtraction['present'] as $amountSource => $rawValue) {
            $normalized = $midtransService->normalizeStrictWholeIdrAmount($rawValue);
            if ($normalized === null) {
                $isSingleEmpty = count($amountExtraction['present']) === 1 && $rawValue === '';
                Log::error('Midtrans recurring webhook: field nominal tidak valid atau explicit null', [
                    'charge_order_id' => $chargeOrderId,
                    'subscription_id' => $subscription->subscription_id,
                    'amount_source'   => $amountSource,
                    'value_type'      => is_scalar($rawValue) ? gettype($rawValue) : gettype($rawValue),
                ]);
                return response()->json(['message' => $isSingleEmpty ? 'Amount is required' : 'Amount mismatch'], 422);
            }
            $normalizedPresent[$amountSource] = $normalized;
        }

        if (count(array_unique(array_values($normalizedPresent))) > 1) {
            Log::error('Midtrans recurring webhook: konflik nilai nominal antar field payload', [
                'charge_order_id' => $chargeOrderId,
                'subscription_id' => $subscription->subscription_id,
                'sources'         => array_keys($normalizedPresent),
            ]);
            return response()->json(['message' => 'Amount mismatch'], 422);
        }

        if (!$midtransService->isExactAmountMatch($grossAmount, $subscription->nominal)) {
            Log::error('Midtrans recurring webhook: nominal siklus tidak cocok dengan nominal subscription', [
                'charge_order_id'      => $chargeOrderId,
                'subscription_id'      => $subscription->subscription_id,
                'subscription_nominal' => $subscription->nominal,
                'payload_gross_amount' => is_scalar($grossAmount) ? $grossAmount : gettype($grossAmount),
            ]);
            return response()->json(['message' => 'Amount mismatch'], 422);
        }

        if (
            !$midtransService->isOptionalIdrCurrencyValid($txData)
            || !$midtransService->isOptionalIdrCurrencyValid($payload)
        ) {
            Log::warning('Midtrans recurring webhook: Mata uang payload bukan IDR atau tipe tidak valid', [
                'charge_order_id' => $chargeOrderId,
                'subscription_id' => $subscription->subscription_id,
            ]);
            return response()->json(['message' => 'Invalid currency'], 422);
        }

        // 8. TZ0.1 Hardening: Seluruh notifikasi sukses (termasuk signed settlement/capture) dan unsigned
        // WAJIB melewati verifikasi Transaction Status API otoritatif sebelum persistensi Donation.
        // Hanya notifikasi bertanda tangan valid untuk status non-sukses (mis. deny/expire/pending) yang dilewati tanpa DB write.
        $isSuccessPayloadStatus = ($transactionStatus === 'settlement') || ($transactionStatus === 'capture');
        $requiresStatusReconciliation = !$hasValidSignature || $isSuccessPayloadStatus;

        $authoritativeOrderId = $chargeOrderId;
        $authoritativeTxId = is_string($transactionId) && trim($transactionId) !== '' ? trim($transactionId) : null;

        if ($requiresStatusReconciliation) {
            try {
                $remoteStatus   = $midtransService->checkTransactionStatus($chargeOrderId);
                $remoteOrderId  = is_string($remoteStatus['order_id'] ?? null) ? trim($remoteStatus['order_id']) : null;
                $remoteTxId     = is_string($remoteStatus['transaction_id'] ?? null) ? trim($remoteStatus['transaction_id']) : null;
                $remoteTxStatus = is_string($remoteStatus['transaction_status'] ?? null)
                    ? strtolower(trim($remoteStatus['transaction_status']))
                    : null;
                $remoteFraud    = array_key_exists('fraud_status', $remoteStatus) && $remoteStatus['fraud_status'] !== null
                    ? (is_string($remoteStatus['fraud_status']) ? strtolower(trim($remoteStatus['fraud_status'])) : '__invalid__')
                    : null;
                $remoteAmount   = $remoteStatus['gross_amount'] ?? null;
                $remoteCode     = (string) ($remoteStatus['status_code'] ?? '');

                // Validasi pengikatan identitas order_id dan transaction_id pada Status API otoritatif
                $trimmedChargeOrderId = trim((string) $chargeOrderId);
                $orderBound = $payloadOrderId !== null
                    ? ($remoteOrderId !== null && $remoteOrderId === trim((string) $payloadOrderId))
                    : (($remoteOrderId !== null && $remoteOrderId === $trimmedChargeOrderId)
                        || ($remoteTxId !== null && $remoteTxId === $trimmedChargeOrderId));
                $txBound = $transactionId === null
                    || $remoteTxId === null
                    || $remoteTxId === trim((string) $transactionId);

                if (!$orderBound || !$txBound) {
                    Log::warning('Midtrans recurring webhook: Identitas transaksi Status API tidak cocok dengan payload', [
                        'charge_order_id' => $chargeOrderId,
                        'subscription_id' => $subscription->subscription_id,
                    ]);
                    return response()->json(['message' => 'Provider verification failed'], 403);
                }

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
                if (!$midtransService->isOptionalIdrCurrencyValid($remoteStatus)) {
                    Log::warning('Midtrans recurring webhook: Mata uang provider bukan IDR', [
                        'charge_order_id' => $chargeOrderId,
                    ]);
                    return response()->json(['message' => 'Invalid currency'], 422);
                }

                // Validasi nominal provider harus cocok eksak dengan subscription dan payload
                if (
                    !$midtransService->isExactAmountMatch($remoteAmount, $subscription->nominal)
                    || !$midtransService->isExactAmountMatch($remoteAmount, $grossAmount)
                ) {
                    Log::warning('Midtrans recurring webhook: Nominal provider tidak cocok dengan subscription', [
                        'charge_order_id' => $chargeOrderId,
                        'remote_amount'   => is_scalar($remoteAmount) ? $remoteAmount : gettype($remoteAmount),
                        'sub_nominal'     => $subscription->nominal,
                    ]);
                    return response()->json(['message' => 'Provider amount mismatch'], 422);
                }

                $authoritativeOrderId = ($remoteOrderId !== null && $remoteOrderId !== '')
                    ? $remoteOrderId
                    : $trimmedChargeOrderId;
                $authoritativeTxId = ($remoteTxId !== null && $remoteTxId !== '') ? $remoteTxId : null;
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

        // 8c. E4 Sandbox Preflight + TZ0.1: Buktikan Kepemilikan Transaksi terhadap Subscription (Linkage Verification)
        // Memverifikasi identitas subscription provider serta keterkaitan ke transaksi otoritatif yang sama.
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

        $remoteSubId = is_string($remoteSub['id'] ?? null) ? trim($remoteSub['id']) : null;
        if ($remoteSubId !== $subscription->subscription_id) {
            Log::warning('Midtrans recurring webhook: ID Subscription provider tidak cocok', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $authoritativeOrderId,
            ]);
            return response()->json(['message' => 'Provider verification failed'], 403);
        }

        $isOwnershipProven = $this->isAuthoritativeTransactionLinkedToSubscription(
            $remoteSub,
            $authoritativeOrderId,
            $authoritativeTxId
        );

        // FAIL CLOSED jika kepemilikan transaksi terhadap subscription tidak dapat dibuktikan
        if (!$isOwnershipProven) {
            Log::warning('Midtrans recurring webhook: Bukti linkage transaksi terhadap subscription tidak ditemukan di provider', [
                'subscription_id' => $subscriptionId,
                'charge_order_id' => $authoritativeOrderId,
            ]);
            return response()->json(['message' => 'Transaction ID not linked to subscription'], 403);
        }

        // 9. Idempotency check awal sebelum transaksi (mendukung representasi order_id maupun transaction_id)
        $lookupOrderIds = array_values(array_unique(array_filter(
            [$authoritativeOrderId, $authoritativeTxId, $chargeOrderId],
            static fn ($v) => is_string($v) && trim($v) !== ''
        )));

        $existingOtherDonation = Donation::whereIn('order_id', $lookupOrderIds)
            ->where(function ($q) use ($subscription) {
                $q->whereNull('subscription_id')
                  ->orWhere('subscription_id', '!=', $subscription->subscription_id);
            })
            ->first();
        if ($existingOtherDonation) {
            Log::error('Midtrans recurring webhook: order_id konflik dengan donasi non-langganan', [
                'charge_order_id' => $authoritativeOrderId,
            ]);
            return response()->json(['message' => 'Order ID conflict'], 409);
        }

        $existingDonation = Donation::whereIn('order_id', $lookupOrderIds)
            ->where('subscription_id', $subscription->subscription_id)
            ->where('status', 'terverifikasi')
            ->first();
        if ($existingDonation) {
            Log::info('Midtrans recurring webhook: siklus sudah terverifikasi sebelumnya (idempotent)', [
                'charge_order_id' => $authoritativeOrderId,
            ]);
            return response()->json(['message' => 'OK'], 200);
        }

        // 10. Catat donasi siklus baru secara atomik dengan row lock untuk mencegah concurrent race condition
        try {
            DB::transaction(function () use (
                $subscription, $authoritativeOrderId, $lookupOrderIds, $grossAmount,
                $transactionStatus, $fraudStatus,
                $progressService, $midtransService
            ) {
                $lockedSub = Subscription::whereKey($subscription->id)
                    ->lockForUpdate()
                    ->first();
                $validatedNominal = $lockedSub
                    ? $midtransService->normalizeStrictWholeIdrAmount($lockedSub->nominal)
                    : null;
                if ($validatedNominal === null || !$midtransService->isExactAmountMatch($grossAmount, $lockedSub->nominal)) {
                    throw new \InvalidArgumentException('Subscription nominal invalid or mismatched.');
                }

                // Cek konflik dengan donasi campaign / user lain di seluruh representasi ID transaksi
                $isUsedByOther = Donation::whereIn('order_id', $lookupOrderIds)
                    ->where(function ($q) use ($subscription) {
                        $q->whereNull('subscription_id')
                          ->orWhere('subscription_id', '!=', $subscription->subscription_id);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($isUsedByOther) {
                    throw new \DomainException("Order ID conflict: used by another donation.");
                }

                // Lock dan periksa kembali donasi siklus yang sudah ada (mencegah duplikasi lintas order_id / transaction_id)
                $lockedExisting = Donation::whereIn('order_id', $lookupOrderIds)
                    ->where('subscription_id', $subscription->subscription_id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting && $lockedExisting->status === 'terverifikasi') {
                    return; // Sudah diproses oleh request paralel / representasi ID lain
                }

                $donation = Donation::create([
                    'campaign_id'                => $subscription->campaign_id,
                    'user_id'                    => $subscription->user_id,
                    'jenis'                      => 'uang',
                    'is_anonim'                  => $subscription->is_anonim,
                    'nominal'                    => $validatedNominal,
                    'is_recurring'               => true,
                    'frequency'                  => $subscription->frequency,
                    'order_id'                   => $authoritativeOrderId,
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
                    'charge_order_id' => $authoritativeOrderId,
                    'donation_id'     => $donation->id,
                    'subscription_id' => $subscription->subscription_id,
                    'nominal'         => $donation->nominal,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            Log::error('Midtrans recurring webhook: nominal subscription tidak valid saat persistensi', [
                'charge_order_id' => $chargeOrderId,
                'subscription_id' => $subscription->subscription_id,
            ]);
            return response()->json(['message' => 'Amount mismatch'], 422);
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

    /**
     * Memverifikasi bahwa transaksi otoritatif yang sama benar-benar terdaftar pada daftar linkage
     * Get Subscription provider, tanpa menerima null, string kosong, atau tipe malformed (TZ0.1).
     */
    protected function isAuthoritativeTransactionLinkedToSubscription(
        array $remoteSub,
        string $authoritativeOrderId,
        ?string $authoritativeTxId
    ): bool {
        $authoritativeIds = array_values(array_unique(array_filter(
            [$authoritativeOrderId, $authoritativeTxId],
            static fn ($v) => is_string($v) && trim($v) !== ''
        )));

        if (empty($authoritativeIds)) {
            return false;
        }

        if (isset($remoteSub['transaction_ids']) && is_array($remoteSub['transaction_ids'])) {
            foreach ($remoteSub['transaction_ids'] as $entry) {
                if (is_string($entry) && trim($entry) !== '' && in_array($entry, $authoritativeIds, true)) {
                    return true;
                }
            }
        }

        if (isset($remoteSub['transactions']) && is_array($remoteSub['transactions'])) {
            foreach ($remoteSub['transactions'] as $tx) {
                if (is_string($tx) && trim($tx) !== '' && in_array($tx, $authoritativeIds, true)) {
                    return true;
                }
                if (!is_array($tx)) {
                    continue;
                }

                $txOrder = is_string($tx['order_id'] ?? null) && trim($tx['order_id']) !== ''
                    ? trim($tx['order_id'])
                    : null;
                $txTxnId = is_string($tx['transaction_id'] ?? null) && trim($tx['transaction_id']) !== ''
                    ? trim($tx['transaction_id'])
                    : null;
                $txGenericId = is_string($tx['id'] ?? null) && trim($tx['id']) !== ''
                    ? trim($tx['id'])
                    : null;

                if ($txOrder !== null && $txOrder !== $authoritativeOrderId) {
                    continue;
                }
                if ($txTxnId !== null && $authoritativeTxId !== null && $txTxnId !== $authoritativeTxId) {
                    continue;
                }

                foreach ([$txGenericId, $txTxnId, $txOrder] as $candidate) {
                    if ($candidate !== null && in_array($candidate, $authoritativeIds, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Mengekstrak sumber nominal recurring charge dengan membedakan absen vs explicit null
     * serta mengumpulkan seluruh field nominal yang hadir untuk validasi konsistensi.
     *
     * @return array{has_selected: bool, selected_raw: mixed, selected_source: ?string, present: array<string, mixed>}
     */
    protected function extractRecurringChargeAmounts(array $payload, array $subData, array $txData): array
    {
        $hasTxGross = array_key_exists('gross_amount', $txData);
        $hasSubAmount = array_key_exists('amount', $subData);
        $hasRootGross = array_key_exists('gross_amount', $payload);

        $hasSelected = false;
        $selectedRaw = null;
        $selectedSource = null;

        if ($hasTxGross) {
            $hasSelected = true;
            $selectedRaw = $txData['gross_amount'];
            $selectedSource = 'transaction.gross_amount';
        } elseif ($hasSubAmount) {
            $hasSelected = true;
            $selectedRaw = $subData['amount'];
            $selectedSource = 'subscription.amount';
        } elseif ($hasRootGross) {
            $hasSelected = true;
            $selectedRaw = $payload['gross_amount'];
            $selectedSource = 'root.gross_amount';
        }

        $present = [];
        if ($hasTxGross) {
            $present['transaction.gross_amount'] = $txData['gross_amount'];
        }
        if ($hasSubAmount) {
            $present['subscription.amount'] = $subData['amount'];
        }
        if ($hasRootGross) {
            $present['root.gross_amount'] = $payload['gross_amount'];
        }

        return [
            'has_selected' => $hasSelected,
            'selected_raw' => $selectedRaw,
            'selected_source' => $selectedSource,
            'present' => $present,
        ];
    }

    /**
     * Menangani notifikasi status langganan (subscription.update_status) secara Verified ACK-only.
     * Event ini bukan bukti pembayaran, selalu diverifikasi via provider Get Subscription API,
     * dan tidak mengubah status lokal, management_state, metadata, maupun progress campaign.
     */
    protected function handleStatusUpdateNotification(
        array $payload,
        MidtransPaymentService $midtransService
    ): \Illuminate\Http\JsonResponse {
        if (array_key_exists('transaction', $payload)) {
            Log::warning('Midtrans subscription status webhook: payload tidak valid', [
                'reason' => 'unexpected_transaction_field',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $subPayload = $payload['subscription'] ?? null;
        if (!is_array($subPayload) || empty($subPayload) || array_is_list($subPayload)) {
            Log::warning('Midtrans subscription status webhook: payload tidak valid', [
                'reason' => 'invalid_subscription_object',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $rawId = $subPayload['id'] ?? null;
        $rawStatus = $subPayload['status'] ?? null;
        if (!is_string($rawId) || trim($rawId) === '' || !is_string($rawStatus) || trim($rawStatus) === '') {
            Log::warning('Midtrans subscription status webhook: payload tidak valid', [
                'reason' => 'missing_or_invalid_id_or_status',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $notificationStatus = strtolower(trim($rawStatus));
        if (!in_array($notificationStatus, ['active', 'inactive', 'cancelled', 'canceled'], true)) {
            Log::warning('Midtrans subscription status webhook: payload tidak valid', [
                'reason' => 'unsupported_notification_status',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        return $this->acknowledgeVerifiedStatusWebhook(
            trim($rawId),
            $notificationStatus,
            'Midtrans subscription status webhook',
            $midtransService
        );
    }

    /**
     * Menangani legacy event subscription.inactive secara terpusat (Verified ACK-only).
     * Mendukung bentuk nested dan flat serta legacy transaction/signature metadata,
     * memvalidasi status root dan nested secara independen (B1),
     * menolak bukti pembayaran sukses/ambigu secara fail-closed (B2, HTTP 422),
     * selalu memverifikasi provider Get Subscription API untuk subscription lokal,
     * dan tidak mengubah status lokal, management_state, guard, metadata, donasi, maupun progress campaign.
     *
     * Presedensi respons (deterministik):
     *   1. 400 Invalid payload (struktur, status B1 tidak valid/konflik, metadata/payment fields cacat) — 0 HTTP call
     *   2. 503 Service unavailable (signing secret kosong saat signature_key ada) — 0 HTTP call
     *   3. 403 Invalid signature (signature_key opsional tidak valid/cacat) — 0 HTTP call
     *   4. Unknown subscription:
     *      - dengan bukti pembayaran ambigu -> 422 Unsupported legacy payment evidence — 0 HTTP call
     *      - status-only -> 200 OK — 0 HTTP call
     *   5. Known subscription (wajib GET /v1/subscriptions/{id} read-only):
     *      - provider GET gagal -> 503 Service unavailable — 1 GET
     *      - data provider mismatch/invalid -> 422 Provider subscription verification failed — 1 GET
     *      - bukti pembayaran ambigu -> 422 Unsupported legacy payment evidence — 1 GET
     *      - status-only valid -> 200 OK (Verified ACK-only) — 1 GET
     */
    protected function handleLegacyInactiveNotification(
        array $payload,
        MidtransPaymentService $midtransService
    ): \Illuminate\Http\JsonResponse {
        $subPayload = null;
        $nestedId = null;
        $nestedStatus = null;
        $rootStatus = null;

        if (array_key_exists('subscription', $payload)) {
            $subPayload = $payload['subscription'];
            if (!is_array($subPayload) || empty($subPayload) || array_is_list($subPayload)) {
                Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                    'reason' => 'invalid_subscription_object',
                ]);
                return response()->json(['message' => 'Invalid payload'], 400);
            }

            if (array_key_exists('id', $subPayload)) {
                $rawNestedId = $subPayload['id'];
                if (!is_string($rawNestedId) || trim($rawNestedId) === '') {
                    Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                        'reason' => 'invalid_nested_subscription_id',
                    ]);
                    return response()->json(['message' => 'Invalid payload'], 400);
                }
                $nestedId = trim($rawNestedId);
            }

            if (array_key_exists('status', $subPayload)) {
                $nestedStatus = $this->parseLegacyInactiveStatus($subPayload['status'], 'invalid_nested_subscription_status');
                if ($nestedStatus === null) {
                    return response()->json(['message' => 'Invalid payload'], 400);
                }
            }
        }

        $flatId = null;
        if (array_key_exists('subscription_id', $payload)) {
            $rawFlatId = $payload['subscription_id'];
            if (!is_string($rawFlatId) || trim($rawFlatId) === '') {
                Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                    'reason' => 'invalid_flat_subscription_id',
                ]);
                return response()->json(['message' => 'Invalid payload'], 400);
            }
            $flatId = trim($rawFlatId);
        }

        if (array_key_exists('status', $payload)) {
            $rootStatus = $this->parseLegacyInactiveStatus($payload['status'], 'invalid_root_subscription_status');
            if ($rootStatus === null) {
                return response()->json(['message' => 'Invalid payload'], 400);
            }
        }

        if ($nestedId !== null && $flatId !== null && $nestedId !== $flatId) {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => 'conflicting_subscription_ids',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        if (
            $nestedStatus !== null
            && $rootStatus !== null
            && $this->canonicalizeLegacyInactiveStatus($nestedStatus) !== $this->canonicalizeLegacyInactiveStatus($rootStatus)
        ) {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => 'conflicting_subscription_statuses',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $subscriptionId = $nestedId ?? $flatId;
        if ($subscriptionId === null) {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => 'missing_subscription_id',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $notificationStatus = $nestedStatus ?? $rootStatus ?? 'inactive';

        $txPayload = null;
        if (array_key_exists('transaction', $payload)) {
            $txPayload = $payload['transaction'];
            if (!is_array($txPayload) || empty($txPayload) || array_is_list($txPayload)) {
                Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                    'reason' => 'invalid_transaction_object',
                ]);
                return response()->json(['message' => 'Invalid payload'], 400);
            }
        }

        $paymentEvidence = $this->inspectLegacyPaymentEvidence($payload, $subPayload, $txPayload, $midtransService);
        if ($paymentEvidence['state'] === 'malformed') {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => $paymentEvidence['reason'],
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $hasOptionalSignature = (is_array($txPayload) && array_key_exists('signature_key', $txPayload))
            || array_key_exists('signature_key', $payload);

        $signatureFailure = $this->verifyOptionalLegacySignature($payload, $subPayload, $txPayload, $subscriptionId);
        if ($signatureFailure !== null) {
            return $signatureFailure;
        }

        return $this->acknowledgeVerifiedStatusWebhook(
            $subscriptionId,
            $notificationStatus,
            'Midtrans legacy inactive webhook',
            $midtransService,
            $paymentEvidence['state'] === 'ambiguous' ? $paymentEvidence['indicator'] : null,
            $hasOptionalSignature
        );
    }

    protected function canonicalizeLegacyInactiveStatus(string $normalizedStatus): string
    {
        return $normalizedStatus === 'canceled' ? 'cancelled' : $normalizedStatus;
    }

    /**
     * Memeriksa keberadaan bukti pembayaran sukses/ambigu pada payload legacy subscription.inactive
     * baik di level root maupun nested transaction.
     *
     * Kebijakan eksplisit Section 12:
     * - settlement_time: nilai null eksplisit dianggap absen (belum settle); string kosong / non-string ditolak 400.
     * - order_id, transaction_id, status_code: diperiksa secara mandiri baik di root maupun nested transaction.
     *
     * @return array{state: string, indicator?: ?string, reason?: string}
     */
    protected function inspectLegacyPaymentEvidence(
        array $payload,
        ?array $subPayload,
        ?array $txPayload,
        MidtransPaymentService $midtransService
    ): array {
        $rootGrossAmount = null;
        if (array_key_exists('gross_amount', $payload)) {
            $rootGrossAmount = $midtransService->normalizeStrictWholeIdrAmount($payload['gross_amount']);
            if ($rootGrossAmount === null) {
                return ['state' => 'malformed', 'reason' => 'invalid_legacy_amount'];
            }
        }

        $nestedGrossAmount = null;
        if (is_array($txPayload) && array_key_exists('gross_amount', $txPayload)) {
            $nestedGrossAmount = $midtransService->normalizeStrictWholeIdrAmount($txPayload['gross_amount']);
            if ($nestedGrossAmount === null) {
                return ['state' => 'malformed', 'reason' => 'invalid_legacy_amount'];
            }
        }

        $subAmount = null;
        if (is_array($subPayload) && array_key_exists('amount', $subPayload)) {
            $subAmount = $midtransService->normalizeStrictWholeIdrAmount($subPayload['amount']);
            if ($subAmount === null) {
                return ['state' => 'malformed', 'reason' => 'invalid_legacy_amount'];
            }
        }

        if (
            array_key_exists('gross_amount', $payload)
            && is_array($txPayload)
            && array_key_exists('gross_amount', $txPayload)
            && (string) $payload['gross_amount'] !== (string) $txPayload['gross_amount']
        ) {
            return ['state' => 'malformed', 'reason' => 'conflicting_transaction_metadata'];
        }

        $effectiveTxGrossAmount = $nestedGrossAmount ?? $rootGrossAmount;
        if ($subAmount !== null && $effectiveTxGrossAmount !== null && $subAmount !== $effectiveTxGrossAmount) {
            return ['state' => 'malformed', 'reason' => 'conflicting_legacy_amounts'];
        }

        $rootTxStatus = $this->parseOptionalLegacyEvidenceString($payload, 'transaction_status', false);
        if ($rootTxStatus === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_transaction_status'];
        }
        $nestedTxStatus = is_array($txPayload)
            ? $this->parseOptionalLegacyEvidenceString($txPayload, 'transaction_status', false)
            : null;
        if ($nestedTxStatus === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_transaction_status'];
        }

        $rootFraudStatus = $this->parseOptionalLegacyEvidenceString($payload, 'fraud_status', true);
        if ($rootFraudStatus === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_fraud_status'];
        }
        $nestedFraudStatus = is_array($txPayload)
            ? $this->parseOptionalLegacyEvidenceString($txPayload, 'fraud_status', true)
            : null;
        if ($nestedFraudStatus === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_fraud_status'];
        }

        $rootSettlementTime = $this->parseOptionalLegacyEvidenceString($payload, 'settlement_time', true);
        if ($rootSettlementTime === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_settlement_time'];
        }
        $nestedSettlementTime = is_array($txPayload)
            ? $this->parseOptionalLegacyEvidenceString($txPayload, 'settlement_time', true)
            : null;
        if ($nestedSettlementTime === false) {
            return ['state' => 'malformed', 'reason' => 'invalid_settlement_time'];
        }

        // Validasi metadata transaksi mandiri harus mendahului klasifikasi ambiguitas agar payload malformed selalu 400 (Section 8.2)
        foreach (array_filter([$payload, is_array($txPayload) ? $txPayload : null]) as $metaSource) {
            foreach (['order_id', 'transaction_id'] as $stringMetaKey) {
                if (array_key_exists($stringMetaKey, $metaSource)) {
                    $metaValue = $metaSource[$stringMetaKey];
                    if (!is_string($metaValue) || trim($metaValue) === '') {
                        return ['state' => 'malformed', 'reason' => 'invalid_transaction_metadata'];
                    }
                }
            }
            if (array_key_exists('status_code', $metaSource)) {
                $statusCodeValue = $metaSource['status_code'];
                $validCode = (is_string($statusCodeValue) && trim($statusCodeValue) !== '') || is_int($statusCodeValue);
                if (!$validCode) {
                    return ['state' => 'malformed', 'reason' => 'invalid_transaction_metadata'];
                }
            }
        }

        $successStatuses = ['settlement', 'capture', 'settled'];

        if ($rootTxStatus !== null && $nestedTxStatus !== null && $rootTxStatus !== $nestedTxStatus) {
            if (in_array($rootTxStatus, $successStatuses, true) || in_array($nestedTxStatus, $successStatuses, true)) {
                return ['state' => 'ambiguous', 'indicator' => 'conflicting_transaction_status_with_success'];
            }
            return ['state' => 'malformed', 'reason' => 'conflicting_transaction_statuses'];
        }

        if ($rootFraudStatus !== null && $nestedFraudStatus !== null && $rootFraudStatus !== $nestedFraudStatus) {
            if ($rootFraudStatus === 'accept' || $nestedFraudStatus === 'accept') {
                return ['state' => 'ambiguous', 'indicator' => 'conflicting_fraud_status_with_accept'];
            }
            return ['state' => 'malformed', 'reason' => 'conflicting_fraud_statuses'];
        }

        $effectiveTxStatus = $nestedTxStatus ?? $rootTxStatus;
        $effectiveFraudStatus = $nestedFraudStatus ?? $rootFraudStatus;
        $hasSettlementTime = ($nestedSettlementTime !== null || $rootSettlementTime !== null);

        $rootStatusCode = $this->parseOptionalLegacyStatusCode($payload);
        $nestedStatusCode = is_array($txPayload) ? $this->parseOptionalLegacyStatusCode($txPayload) : null;

        if ($effectiveTxStatus !== null && in_array($effectiveTxStatus, $successStatuses, true)) {
            if (
                ($rootStatusCode !== null && $nestedStatusCode !== null && $rootStatusCode !== $nestedStatusCode)
                || ($rootStatusCode !== null && $rootStatusCode !== '200')
                || ($nestedStatusCode !== null && $nestedStatusCode !== '200')
            ) {
                return ['state' => 'ambiguous', 'indicator' => 'conflicting_success_status_code'];
            }

            return [
                'state' => 'ambiguous',
                'indicator' => $effectiveTxStatus === 'capture'
                    ? 'transaction_status_capture'
                    : 'transaction_status_settlement',
            ];
        }

        if ($effectiveFraudStatus === 'accept') {
            return ['state' => 'ambiguous', 'indicator' => 'fraud_status_accept'];
        }

        if ($hasSettlementTime) {
            return ['state' => 'ambiguous', 'indicator' => 'settlement_time_present'];
        }

        if (is_array($txPayload)) {
            foreach (['order_id', 'transaction_id', 'status_code', 'gross_amount'] as $metaKey) {
                if (array_key_exists($metaKey, $payload) && array_key_exists($metaKey, $txPayload)) {
                    $rootMeta = $this->normalizeLegacyMetadataScalar($payload[$metaKey]);
                    $nestedMeta = $this->normalizeLegacyMetadataScalar($txPayload[$metaKey]);
                    if ($rootMeta === null || $nestedMeta === null || $rootMeta !== $nestedMeta) {
                        return ['state' => 'malformed', 'reason' => 'conflicting_transaction_metadata'];
                    }
                }
            }
        }

        return ['state' => 'none', 'indicator' => null];
    }

    protected function parseOptionalLegacyEvidenceString(array $source, string $key, bool $allowNull): string|false|null
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }

        $value = $source[$key];
        if ($allowNull && $value === null) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        return strtolower(trim($value));
    }

    protected function parseOptionalLegacyStatusCode(array $source): ?string
    {
        if (!array_key_exists('status_code', $source)) {
            return null;
        }

        $value = $source['status_code'];
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function normalizeLegacyMetadataScalar(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    protected function parseLegacyInactiveStatus(mixed $rawStatus, string $invalidTypeReason): ?string
    {
        if (!is_string($rawStatus) || trim($rawStatus) === '') {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => $invalidTypeReason,
            ]);
            return null;
        }

        $normalized = strtolower(trim($rawStatus));
        if (!in_array($normalized, ['inactive', 'cancelled', 'canceled'], true)) {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => 'unsupported_notification_status',
            ]);
            return null;
        }

        return $normalized;
    }

    protected function verifyOptionalLegacySignature(
        array $payload,
        ?array $subPayload,
        ?array $txPayload,
        string $subscriptionId
    ): ?\Illuminate\Http\JsonResponse {
        $hasTxSignature = is_array($txPayload) && array_key_exists('signature_key', $txPayload);
        $hasRootSignature = array_key_exists('signature_key', $payload);
        if ($hasTxSignature && $hasRootSignature && $txPayload['signature_key'] !== $payload['signature_key']) {
            Log::warning('Midtrans legacy inactive webhook: payload tidak valid', [
                'reason' => 'conflicting_signature_keys',
            ]);
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        if (!$hasTxSignature && !$hasRootSignature) {
            return null;
        }

        $signatureKey = $hasTxSignature ? $txPayload['signature_key'] : $payload['signature_key'];
        if (!is_string($signatureKey) || trim($signatureKey) === '') {
            Log::warning('Midtrans legacy inactive webhook: signature tidak valid', [
                'subscription_id' => $subscriptionId,
                'reason' => 'malformed_signature_key',
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $signingSecret = config('services.midtrans.signature_secret_key', '');
        if (empty($signingSecret)) {
            Log::error('Midtrans legacy inactive webhook: signature secret key tidak dikonfigurasi');
            return response()->json(['message' => 'Service unavailable'], 503);
        }

        $sigOrderId = (is_array($txPayload) ? ($txPayload['order_id'] ?? $txPayload['transaction_id'] ?? null) : null)
            ?? ($payload['order_id'] ?? $payload['transaction_id'] ?? null);
        $sigStatusCode = (is_array($txPayload) ? ($txPayload['status_code'] ?? null) : null)
            ?? ($payload['status_code'] ?? null);
        $sigGrossAmount = (is_array($txPayload) ? ($txPayload['gross_amount'] ?? null) : null)
            ?? (is_array($subPayload) ? ($subPayload['amount'] ?? null) : null)
            ?? ($payload['gross_amount'] ?? null);

        $validOrderId = is_string($sigOrderId) && trim($sigOrderId) !== '';
        $validStatusCode = (is_string($sigStatusCode) && $sigStatusCode !== '') || is_int($sigStatusCode);
        $validGrossAmount = (is_string($sigGrossAmount) && $sigGrossAmount !== '') || is_int($sigGrossAmount);

        if (!$validOrderId || !$validStatusCode || !$validGrossAmount) {
            Log::warning('Midtrans legacy inactive webhook: input signature tidak lengkap', [
                'subscription_id' => $subscriptionId,
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $expectedSignature = hash(
            'sha512',
            (string) $sigOrderId . (string) $sigStatusCode . (string) $sigGrossAmount . $signingSecret
        );
        if (!hash_equals($expectedSignature, $signatureKey)) {
            Log::warning('Midtrans legacy inactive webhook: signature tidak valid', [
                'subscription_id' => $subscriptionId,
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        return null;
    }

    /**
     * Memverifikasi notifikasi status terhadap provider Get Subscription API dan mengembalikan
     * ACK-only HTTP 200 tanpa mengubah state bisnis lokal, guard, donasi, maupun progress campaign,
     * atau menolak secara fail-closed (HTTP 422) jika terdapat bukti pembayaran ambigu pada event legacy.
     */
    protected function acknowledgeVerifiedStatusWebhook(
        string $subscriptionId,
        string $notificationStatus,
        string $logPrefix,
        MidtransPaymentService $midtransService,
        ?string $ambiguousPaymentIndicator = null,
        bool $hasOptionalSignature = false
    ): \Illuminate\Http\JsonResponse {
        $subscription = Subscription::where('subscription_id', $subscriptionId)->first();
        if (!$subscription) {
            if ($ambiguousPaymentIndicator !== null) {
                Log::warning("{$logPrefix}: ambiguous legacy payment evidence rejected", [
                    'reason' => 'legacy_payment_evidence_ambiguous',
                    'indicator' => $ambiguousPaymentIndicator,
                    'subscription_id' => $subscriptionId,
                    'subscription_known' => false,
                    'notification_status' => $notificationStatus,
                    'has_signature' => $hasOptionalSignature,
                ]);
                return response()->json(['message' => 'Unsupported legacy payment evidence'], 422);
            }

            Log::info("{$logPrefix} acknowledged for unknown local subscription", [
                'subscription_id' => $subscriptionId,
                'notification_status' => $notificationStatus,
            ]);
            return response()->json(['message' => 'OK'], 200);
        }

        try {
            $remoteSub = $midtransService->getSubscription($subscriptionId);
        } catch (\Throwable $e) {
            $context = [
                'subscription_id' => $subscriptionId,
                'notification_status' => $notificationStatus,
                'has_ambiguous_payment_evidence' => $ambiguousPaymentIndicator !== null,
            ];
            if ($ambiguousPaymentIndicator !== null) {
                $context['ambiguity_reason'] = 'legacy_payment_evidence_ambiguous';
                $context['ambiguity_indicator'] = $ambiguousPaymentIndicator;
            }
            Log::warning("{$logPrefix}: provider verification failed", $context);
            return response()->json(['message' => 'Service unavailable'], 503);
        }

        $verification = $this->verifyProviderSubscriptionForStatusWebhook($subscription, $remoteSub, $midtransService);
        if (!$verification['valid']) {
            $context = [
                'subscription_id' => $subscriptionId,
                'reason' => $verification['reason'],
                'has_ambiguous_payment_evidence' => $ambiguousPaymentIndicator !== null,
            ];
            if ($ambiguousPaymentIndicator !== null) {
                $context['ambiguity_reason'] = 'legacy_payment_evidence_ambiguous';
                $context['ambiguity_indicator'] = $ambiguousPaymentIndicator;
            }
            Log::warning("{$logPrefix}: provider data mismatch", $context);
            return response()->json(['message' => 'Provider subscription verification failed'], 422);
        }

        $providerStatus = $verification['provider_status'];
        $localStatus = strtolower((string) $subscription->status);

        if ($ambiguousPaymentIndicator !== null) {
            Log::warning("{$logPrefix}: ambiguous legacy payment evidence rejected", [
                'reason' => 'legacy_payment_evidence_ambiguous',
                'indicator' => $ambiguousPaymentIndicator,
                'subscription_id' => $subscriptionId,
                'subscription_known' => true,
                'notification_status' => $notificationStatus,
                'provider_status' => $providerStatus,
                'local_status' => $localStatus,
                'management_state' => $subscription->management_state,
                'has_signature' => $hasOptionalSignature,
            ]);
            return response()->json(['message' => 'Unsupported legacy payment evidence'], 422);
        }

        if ($notificationStatus !== $providerStatus) {
            Log::info("{$logPrefix}: stale or late notification acknowledged", [
                'subscription_id' => $subscriptionId,
                'notification_status' => $notificationStatus,
                'provider_status' => $providerStatus,
                'local_status' => $localStatus,
                'management_state' => $subscription->management_state,
            ]);
        }

        if ($providerStatus !== $localStatus) {
            Log::warning("{$logPrefix}: provider and local status drift detected", [
                'subscription_id' => $subscriptionId,
                'provider_status' => $providerStatus,
                'local_status' => $localStatus,
                'management_state' => $subscription->management_state,
            ]);
        }

        Log::info("{$logPrefix} acknowledged (ACK-only)", [
            'subscription_id' => $subscriptionId,
            'notification_status' => $notificationStatus,
            'provider_status' => $providerStatus,
            'local_status' => $localStatus,
            'management_state' => $subscription->management_state,
        ]);

        return response()->json(['message' => 'OK'], 200);
    }

    /**
     * Memverifikasi identitas, nominal, mata uang, jadwal, dan status langganan dari provider
     * secara ketat untuk event subscription.update_status.
     */
    protected function verifyProviderSubscriptionForStatusWebhook(
        Subscription $subscription,
        mixed $remote,
        MidtransPaymentService $midtransService
    ): array {
        if (!is_array($remote)) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'invalid_provider_response'];
        }

        if (!is_string($remote['id'] ?? null) || $remote['id'] !== $subscription->subscription_id) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'id_mismatch'];
        }

        $amount = $remote['amount'] ?? null;
        if (!$midtransService->isExactAmountMatch($amount, $subscription->nominal)) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'amount_mismatch'];
        }

        $currency = $remote['currency'] ?? null;
        if (!is_string($currency) || strtoupper(trim($currency)) !== 'IDR') {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'currency_mismatch'];
        }

        $schedule = $remote['schedule'] ?? null;
        if (!is_array($schedule)) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'schedule_missing'];
        }

        $expectedInterval = (int) $subscription->interval;
        $interval = $schedule['interval'] ?? null;
        $intervalValid = $expectedInterval > 0 && (
            (is_int($interval) && $interval === $expectedInterval)
            || (is_string($interval) && ctype_digit($interval) && (string) $expectedInterval === $interval)
        );
        if (!$intervalValid) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'interval_mismatch'];
        }

        $expectedUnit = $subscription->interval_unit ?: ($subscription->frequency === 'mingguan' ? 'week' : 'month');
        $intervalUnit = $schedule['interval_unit'] ?? null;
        if (!is_string($intervalUnit) || $intervalUnit !== $expectedUnit) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'interval_unit_mismatch'];
        }

        $rawStatus = $remote['status'] ?? null;
        if (!is_string($rawStatus) || trim($rawStatus) === '') {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'missing_provider_status'];
        }

        $providerStatus = strtolower(trim($rawStatus));
        if (!in_array($providerStatus, ['active', 'inactive', 'cancelled', 'canceled'], true)) {
            return ['valid' => false, 'provider_status' => null, 'reason' => 'invalid_provider_status'];
        }

        return ['valid' => true, 'provider_status' => $providerStatus, 'reason' => null];
    }
}
