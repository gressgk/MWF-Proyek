<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Services\CampaignProgressService;
use App\Services\MidtransPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MidtransWebhookController — Menerima dan memproses HTTP notification dari Midtrans.
 *
 * ENDPOINT: POST /midtrans/webhook  (dikecualikan dari CSRF, bukan dari auth umum)
 *
 * ALUR VERIFIKASI:
 *   1. Parse payload JSON.
 *   2. Validasi field minimum.
 *   3. Verifikasi signature SHA512(order_id + status_code + gross_amount + signature_secret_key).
 *      CATATAN: Menggunakan Signature Secret Key (bukan Server Key API).
 *      Server Key API tetap digunakan oleh MidtransPaymentService untuk Snap & Get Status API.
 *   4. Validasi konsistensi status_code dan transaction_status.
 *   5. Ambil Donation berdasarkan order_id — pastikan jenis='uang'.
 *   6. Cocokkan gross_amount secara eksak IDR (tanpa pembulatan float).
 *   7. Jalankan transisi status terpadu secara atomik melalui MidtransPaymentService.
 */
class MidtransWebhookController extends Controller
{
    public function handle(
        Request $request,
        CampaignProgressService $progressService,
        MidtransPaymentService $midtransService
    ): \Illuminate\Http\JsonResponse {
        // 1. Parse payload JSON dari Midtrans
        $payload = $request->all();

        $orderId           = $payload['order_id'] ?? null;
        $statusCode        = $payload['status_code'] ?? null;
        $grossAmount       = $payload['gross_amount'] ?? null;
        $transactionStatus = $payload['transaction_status'] ?? null;
        $fraudStatus       = $payload['fraud_status'] ?? null;
        $signatureKey      = $payload['signature_key'] ?? null;

        // 2. Validasi kelengkapan field wajib
        if (!$orderId || !$statusCode || !$grossAmount || !$transactionStatus || !$signatureKey) {
            Log::warning('Midtrans webhook: payload tidak lengkap', [
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Invalid payload'], 400);
        }

        // 3. Verifikasi signature SHA512 menggunakan Signature Secret Key
        // CATATAN: Midtrans menyediakan dua credential berbeda:
        //   - Server Key API  → autentikasi HTTP Basic untuk Snap & Get Status API (di MidtransPaymentService)
        //   - Signature Secret Key → menandatangani payload webhook notification (di sini)
        $signingSecret = config('services.midtrans.signature_secret_key', '');
        if (empty($signingSecret)) {
            Log::error('Midtrans webhook: signature secret key tidak dikonfigurasi');
            return response()->json(['message' => 'Service unavailable'], 503);
        }

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $signingSecret);
        if (!hash_equals($expectedSignature, $signatureKey)) {
            Log::warning('Midtrans webhook: signature tidak valid', [
                'order_id' => $orderId,
            ]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // 4. Validasi konsistensi status_code dan transaction_status
        if (!$midtransService->isStatusCodeConsistent((string) $statusCode, (string) $transactionStatus)) {
            Log::warning('Midtrans webhook: kombinasi status_code dan transaction_status tidak konsisten', [
                'order_id'           => $orderId,
                'status_code'        => $statusCode,
                'transaction_status' => $transactionStatus,
            ]);

            return response()->json(['message' => 'Inconsistent status combination'], 422);
        }

        // 5. Cari Donation berdasarkan order_id — pastikan jenis='uang'
        $donation = Donation::where('order_id', $orderId)
            ->where('jenis', 'uang')
            ->first();

        if (!$donation) {
            Log::warning('Midtrans webhook: order tidak ditemukan atau bukan donasi uang', [
                'order_id' => $orderId,
            ]);

            // HTTP 200 agar Midtrans tidak retry tanpa henti untuk order yang memang tidak terdaftar
            return response()->json(['message' => 'Order not found'], 200);
        }

        // 6. Validasi eksak gross_amount (tanpa pembulatan float, tolak desimal tak nol)
        if (!$midtransService->isExactAmountMatch($grossAmount, (int) $donation->nominal)) {
            Log::error('Midtrans webhook: nominal tidak cocok — potensi manipulasi', [
                'order_id'        => $orderId,
                'db_nominal'      => $donation->nominal,
                'provider_amount' => $grossAmount,
            ]);

            return response()->json(['message' => 'Amount mismatch'], 422);
        }

        // 7. Jika status saat ini adalah 'gagal' dan incoming adalah 'settlement' / 'capture' (accept),
        // lakukan pemeriksaan ke Midtrans Get Status API DI LUAR database transaction (mencegah penahanan row lock)
        $isPreVerifiedRecovery = false;
        if ($donation->status === 'gagal' &&
            ($transactionStatus === 'settlement' || ($transactionStatus === 'capture' && $fraudStatus === 'accept'))
        ) {
            try {
                $remoteStatus = $midtransService->checkTransactionStatus($donation->order_id);
                if ($midtransService->validateProviderSettlement($remoteStatus, $donation)) {
                    $isPreVerifiedRecovery = true;
                } else {
                    Log::warning('Midtrans webhook: Out-of-order settlement ditolak karena data provider tidak lengkap/tidak valid', [
                        'order_id' => $donation->order_id,
                        'remote' => $remoteStatus,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Midtrans webhook: Gagal menghubungi Status API untuk out-of-order recovery', [
                    'order_id' => $donation->order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 8. Jalankan transisi status terpadu secara atomik dengan row lock (bebas dari HTTP call di dalam lock)
        $result = $midtransService->processTransactionTransition(
            $donation,
            $transactionStatus,
            $fraudStatus,
            (string) $grossAmount,
            $progressService,
            $isPreVerifiedRecovery
        );

        // 9. E4 Sandbox Preflight: Jika donasi ini merupakan pembayaran awal donasi rutin (INIT-SUB-...) yang berhasil terverifikasi
        // dan belum memiliki subscription_id, selesaikan pembuatan Subscription API secara aman & terverifikasi provider!
        $donation->refresh();
        if (!empty($donation->frequency) && empty($donation->subscription_id) && $donation->status === 'terverifikasi') {
            // signature_key webhook TIDAK mencakup saved_token_id.
            // Jangan menerima saved_token_id dari webhook sebagai bukti yang cukup tanpa pengecekan provider!
            try {
                $remoteStatus = $midtransService->checkTransactionStatus($donation->order_id);
                $remoteOrderId      = $remoteStatus['order_id'] ?? null;
                $remoteCode         = (string) ($remoteStatus['status_code'] ?? '');
                $remoteTxStatus     = $remoteStatus['transaction_status'] ?? null;
                $remoteFraud        = $remoteStatus['fraud_status'] ?? null;
                $remoteAmount       = $remoteStatus['gross_amount'] ?? null;
                $remoteCurrency     = $remoteStatus['currency'] ?? null;
                $providerSavedToken = $remoteStatus['saved_token_id'] ?? null;

                $isRemoteSuccess = ($remoteTxStatus === 'settlement') || ($remoteTxStatus === 'capture' && $remoteFraud === 'accept');

                // Validasi ketat fail-closed
                if ((!empty($remoteOrderId) && $remoteOrderId !== $donation->order_id) ||
                    $remoteCode !== '200' ||
                    !$isRemoteSuccess ||
                    empty($remoteAmount) ||
                    !$midtransService->isExactAmountMatch($remoteAmount, (int) $donation->nominal) ||
                    (!empty($remoteCurrency) && strtoupper($remoteCurrency) !== 'IDR') ||
                    empty($providerSavedToken)
                ) {
                    Log::warning('Midtrans webhook: Verifikasi provider untuk initial charge gagal atau data tidak valid', [
                        'order_id' => $donation->order_id,
                    ]);
                    return response()->json(['message' => 'Provider verification for initial charge failed'], 403);
                }

                // Cek jika webhook menyertakan saved_token_id yang diubah tanpa mengubah signature
                $webhookSavedToken = $payload['saved_token_id'] ?? null;
                if (!empty($webhookSavedToken) && !hash_equals($providerSavedToken, $webhookSavedToken)) {
                    Log::warning('Midtrans webhook: saved_token_id pada webhook diubah dan tidak sesuai dengan token resmi provider', [
                        'order_id' => $donation->order_id,
                    ]);
                    return response()->json(['message' => 'Forged saved_token_id detected'], 403);
                }

                // Aktifkan subscription menggunakan token resmi yang diverifikasi dari provider
                $midtransService->activateSubscriptionFromInitialCharge($donation, $providerSavedToken);
            } catch (\Throwable $e) {
                Log::error('Midtrans webhook: Gagal menghubungi Status API provider saat verifikasi initial charge', [
                    'order_id' => $donation->order_id,
                    'error'    => $e->getMessage(),
                ]);
                return response()->json(['message' => 'Provider verification unavailable'], 503);
            }
        }

        Log::info('Midtrans webhook: processed', array_merge(
            ['order_id' => $orderId],
            $result
        ));

        return response()->json(['message' => 'OK', 'result' => $result['status']], 200);
    }
}
