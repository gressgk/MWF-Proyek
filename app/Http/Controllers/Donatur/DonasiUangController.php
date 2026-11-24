<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
use App\Services\MidtransApiException;
use App\Services\MidtransConfigurationException;
use App\Services\MidtransConnectionException;
use App\Services\MidtransPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DonasiUangController extends Controller
{
    /**
     * Menampilkan form donasi uang untuk campaign yang dipilih.
     *
     * @param Campaign $campaign
     * @return \Illuminate\View\View
     */
    public function index(Campaign $campaign)
    {
        abort_unless(
            $campaign->status === 'aktif' && in_array($campaign->tipe_bantuan, ['uang', 'keduanya']),
            404
        );

        $idempotencyKey = Str::uuid()->toString();

        return view('donatur.donasi-uang', compact('campaign', 'idempotencyKey'));
    }

    /**
     * Memproses checkout donasi uang dan membuat Snap Token Midtrans.
     *
     * @param Request $request
     * @param Campaign $campaign
     * @param MidtransPaymentService $midtransService
     * @return \Illuminate\Http\JsonResponse|\Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Campaign $campaign, MidtransPaymentService $midtransService)
    {
        $this->authorize('create', Donation::class);
        // 1. Validasi campaign aktif dan menerima uang
        abort_unless(
            $campaign->status === 'aktif' && in_array($campaign->tipe_bantuan, ['uang', 'keduanya']),
            404
                );
        // 2. Validasi autentikasi donatur
        $user = getAuthUser();
        $this->authorize('create', Donation::class);
        abort_unless($user && $user->role === 'donatur', 403);

        // 3. Batas nominal (konfigurasi sementara: min Rp1.000, max Rp100.000.000)
        $minNominal = config('services.midtrans.min_amount', 1000);
        $maxNominal = config('services.midtrans.max_amount', 100000000);

        $validated = $request->validate([
            'nominal' => ['required', 'integer', "min:{$minNominal}", "max:{$maxNominal}"],
            'nominal_preset' => ['nullable', 'string'],
            'is_anonim' => ['nullable', 'boolean'],
            'is_recurring' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'in:mingguan,bulanan'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ], [
            'nominal.required' => 'Nominal donasi wajib diisi.',
            'nominal.integer' => 'Nominal donasi harus berupa angka bulat dalam Rupiah.',
            'nominal.min' => 'Nominal donasi minimal Rp ' . number_format($minNominal, 0, ',', '.') . '.',
            'nominal.max' => 'Nominal donasi melebihi batas maksimum platform (Rp ' . number_format($maxNominal, 0, ',', '.') . ').',
        ]);

        // 4. Penanganan pilihan recurring (Donasi Rutin — Fase E4 Midtrans Subscription API)
        if ($request->boolean('is_recurring')) {
            $frequency = $request->input('frequency');
            if (empty($frequency) || !in_array($frequency, ['mingguan', 'bulanan'], true)) {
                return $this->respondError(
                    $request,
                    'Frekuensi donasi rutin wajib dipilih (mingguan atau bulanan).',
                    422,
                    'frequency'
                );
            }

            // 4a. Persetujuan Donatur wajib diberikan secara eksplisit
            $consent = $request->input('recurring_consent');
            if (empty($consent) || !in_array($consent, ['accepted', '1', 'true', true], true)) {
                return $this->respondError(
                    $request,
                    'Persetujuan syarat dan ketentuan donasi rutin wajib disetujui.',
                    422,
                    'recurring_consent'
                );
            }

            $paymentType = $request->input('payment_type', 'credit_card');
            if (!$midtransService->isPaymentTypeSupportedForRecurring($paymentType)) {
                return $this->respondError(
                    $request,
                    "Metode pembayaran '{$paymentType}' tidak mendukung donasi rutin otomatis saat ini. Silakan gunakan kartu kredit/debit.",
                    422,
                    'payment_type'
                );
            }

            $paymentToken = $request->input('payment_token');
            if (empty($paymentToken)) {
                return $this->respondError(
                    $request,
                    'Token pembayaran (saved card / tokenisasi) diperlukan untuk mengaktifkan donasi rutin.',
                    422,
                    'payment_token'
                );
            }

            // Dilarang menerima nomor kartu mentah sebagai payment token
            if (preg_match('/^\d{13,19}$/', str_replace([' ', '-'], '', $paymentToken))) {
                return $this->respondError(
                    $request,
                    'Nomor kartu mentah dilarang digunakan langsung sebagai token langganan. Gunakan saved_token_id resmi.',
                    422,
                    'midtrans'
                );
            }

            $idempotencyKey = $validated['idempotency_key'] ?? null;

            // Idempotency check untuk subscription yang sudah aktif
            if ($idempotencyKey) {
                $existingSub = Subscription::where('idempotency_key', $idempotencyKey)->first();
                if ($existingSub) {
                    if ((int) $existingSub->user_id !== (int) $user->id) {
                        return $this->respondError($request, 'Kunci transaksi tidak valid untuk akun Anda.', 422, 'idempotency_key');
                    }
                    if ((int) $existingSub->campaign_id !== (int) $campaign->id ||
                        (int) $existingSub->nominal !== (int) $validated['nominal'] ||
                        $existingSub->frequency !== $frequency) {
                        return $this->respondError($request, 'Kunci transaksi telah digunakan dengan parameter langganan yang berbeda.', 422, 'idempotency_key');
                    }

                    if ($request->expectsJson()) {
                        return response()->json([
                            'success'           => true,
                            'subscription_id'   => $existingSub->subscription_id,
                            'status'            => $existingSub->status,
                            'frequency'         => $existingSub->frequency,
                            'nominal'           => (int) $existingSub->nominal,
                            'next_execution_at' => $existingSub->next_execution_at,
                            'message'           => 'Langganan donasi rutin yang sama ditemukan (idempotent).',
                        ], 200);
                    }

                    return redirect()->route('donatur.riwayat')->with('info', 'Langganan donasi rutin Anda sudah aktif.');
                }
            }

            // Alur Resmi E4.3: Initial Card Charge dengan save_token_id=true
            // 1. Buat record donasi awal berstatus pending untuk melacak charge aktivasi
            $initOrderId = 'INIT-SUB-' . time() . '-' . $campaign->id . '-' . strtoupper(Str::random(6));
            try {
                $initialDonation = DB::transaction(function () use ($campaign, $user, $validated, $initOrderId, $idempotencyKey, $frequency, $request) {
                    return Donation::create([
                        'campaign_id'           => $campaign->id,
                        'user_id'               => $user->id,
                        'jenis'                 => 'uang',
                        'is_anonim'             => $request->boolean('is_anonim'),
                        'nominal'               => (int) $validated['nominal'],
                        'is_recurring'          => false, // Charge awal bukan cycle berulang
                        'frequency'             => $frequency,
                        'order_id'              => $initOrderId,
                        'idempotency_key'       => $idempotencyKey ? "init_{$idempotencyKey}" : Str::uuid()->toString(),
                        'status'                => 'menunggu_pembayaran',
                        'payment_status_detail' => 'initiating_card_charge',
                    ]);
                });
            } catch (QueryException $e) {
                if ($e->getCode() == 23000 && $idempotencyKey) {
                    $existingInit = Donation::where('idempotency_key', "init_{$idempotencyKey}")->first();
                    if ($existingInit && $existingInit->subscription_id) {
                        $sub = Subscription::where('subscription_id', $existingInit->subscription_id)->first();
                        if ($sub) {
                            if ($request->expectsJson()) {
                                return response()->json([
                                    'success'           => true,
                                    'subscription_id'   => $sub->subscription_id,
                                    'status'            => $sub->status,
                                    'frequency'         => $sub->frequency,
                                    'nominal'           => (int) $sub->nominal,
                                    'next_execution_at' => $sub->next_execution_at,
                                    'message'           => 'Langganan donasi rutin yang sama ditemukan (idempotent).',
                                ], 200);
                            }
                            return redirect()->route('donatur.riwayat')->with('info', 'Langganan donasi rutin Anda sudah aktif.');
                        }
                    }
                }
                return $this->respondError($request, 'Terjadi konflik transaksi bersamaan. Silakan coba kembali.', 409, 'idempotency_key');
            }

            // 2. Eksekusi Initial Card Charge ke Midtrans Core API
            try {
                $chargeResult = $midtransService->chargeInitialCardWithSaveToken(
                    $initialDonation,
                    $paymentToken,
                    $user,
                    $campaign
                );

                $txStatus     = $chargeResult['transaction_status'] ?? null;
                $redirectUrl  = $chargeResult['redirect_url'] ?? null;
                $fraudStatus  = $chargeResult['fraud_status'] ?? null; // E4.3.1: Jangan pernah default ke 'accept'!
                $savedTokenId = $chargeResult['saved_token_id'] ?? null;

                // 3. Jika memerlukan otorisasi 3DS di browser
                if (!empty($redirectUrl) || ($txStatus === 'pending' && !empty($redirectUrl))) {
                    $initialDonation->update([
                        'payment_status_detail' => 'awaiting_3ds',
                    ]);

                    if ($request->expectsJson()) {
                        return response()->json([
                            'success'      => true,
                            'requires_3ds' => true,
                            'redirect_url' => $redirectUrl,
                            'order_id'     => $initialDonation->order_id,
                            'message'      => 'Otorisasi 3DS diperlukan untuk memvalidasi kartu.',
                        ], 200);
                    }

                    return redirect()->away($redirectUrl);
                }

                // 4. Jika charge awal ditolak oleh penyedia
                if (in_array($txStatus, ['deny', 'cancel', 'expire'], true) || $fraudStatus === 'deny') {
                    $initialDonation->update([
                        'status'                => 'gagal',
                        'payment_status_detail' => 'charge_denied',
                    ]);

                    return $this->respondError(
                        $request,
                        'Otorisasi kartu pembayaran awal ditolak oleh bank atau sistem pembayaran.',
                        422,
                        'midtrans'
                    );
                }

                // 5. E4.3.1: Perketat validasi initial charge - jika capture tanpa fraud_status atau status belum definitif, rekonsiliasi aktif
                if ($txStatus === 'capture' && empty($fraudStatus)) {
                    try {
                        $remote = $midtransService->checkTransactionStatus($initialDonation->order_id);
                        $txStatus     = $remote['transaction_status'] ?? null;
                        $fraudStatus  = $remote['fraud_status'] ?? null;
                        $savedTokenId = $savedTokenId ?: ($remote['saved_token_id'] ?? null);
                    } catch (\Throwable $e) {
                        // Jangan default accept jika rekonsiliasi gagal
                    }
                }

                $isChargeSuccess = ($txStatus === 'settlement') || ($txStatus === 'capture' && $fraudStatus === 'accept');
                if (!$isChargeSuccess) {
                    $initialDonation->update([
                        'payment_status_detail' => 'charge_' . ($txStatus ?: 'unknown'),
                    ]);

                    return $this->respondError(
                        $request,
                        'Status otorisasi kartu belum selesai atau membutuhkan konfirmasi lanjutan.',
                        422,
                        'midtrans'
                    );
                }

                // Fail-closed jika saved_token_id tidak dikembalikan
                if (empty($savedTokenId)) {
                    $initialDonation->update([
                        'payment_status_detail' => 'missing_saved_token',
                    ]);

                    return $this->respondError(
                        $request,
                        'Fitur pembayaran berulang (One Click / Saved Card) belum aktif pada akun merchant Midtrans. Hubungi pengelola platform.',
                        422,
                        'midtrans'
                    );
                }

                // Tandai donasi awal terverifikasi & perbarui progress campaign
                $initialDonation->update([
                    'status'                     => 'terverifikasi',
                    'payment_status_detail'      => 'settled',
                    'webhook_processed_at'       => now(),
                    'webhook_transaction_status' => $txStatus,
                    'webhook_fraud_status'       => $fraudStatus,
                    'tanggal_diterima'           => now(),
                ]);
                app(\App\Services\CampaignProgressService::class)->recalculate($campaign);

                // 6. Eksekusi alur aktivasi Subscription terpusat dan aman (E4.3.1)
                $activation = $midtransService->activateSubscriptionFromInitialCharge($initialDonation, $savedTokenId);
                if (!$activation['success']) {
                    return $this->respondError($request, $activation['message'], 422, 'midtrans');
                }

                $subscription = $activation['subscription'];

                if ($request->expectsJson()) {
                    return response()->json([
                        'success'           => true,
                        'subscription_id'   => $subscription->subscription_id,
                        'status'            => $subscription->status,
                        'frequency'         => $subscription->frequency,
                        'nominal'           => (int) $subscription->nominal,
                        'next_execution_at' => $subscription->next_execution_at,
                        'message'           => 'Donasi rutin berhasil diaktifkan melalui Midtrans Subscription API.',
                    ], 201);
                }

                return redirect()->route('donatur.riwayat')->with('success', 'Donasi rutin ' . $frequency . ' berhasil didaftarkan.');
            } catch (MidtransApiException $e) {
                // Sanitasi pesan error: status 401/403 adalah masalah konfigurasi server,
                // jangan ekspos pesan teknis/credential Midtrans ke user.
                $httpStatus = $e->getCode() ?: 422;
                $userMessage = in_array($httpStatus, [401, 403], true)
                    ? 'Layanan donasi rutin tidak dapat diakses saat ini. Silakan coba kembali atau hubungi pengelola.'
                    : $e->getMessage();
                return $this->respondError($request, $userMessage, $httpStatus, 'midtrans');
            } catch (MidtransConnectionException $e) {
                return $this->respondError($request, 'Koneksi ke sistem pembayaran sedang lambat. Silakan coba beberapa saat lagi.', 504, 'midtrans');
            } catch (\Throwable $e) {
                Log::error('Gagal membuat langganan donasi rutin', [
                    'campaign_id' => $campaign->id,
                    'user_id'     => $user->id,
                    'error'       => $e->getMessage(),
                ]);
                return $this->respondError($request, 'Layanan donasi rutin saat ini mengalami gangguan. Silakan coba kembali.', 503, 'midtrans');
            }
        }

        $idempotencyKey = $validated['idempotency_key'] ?? null;

        // 5. Cek idempotency: audit payload dan kepemilikan key lama
        if ($idempotencyKey) {
            $existing = Donation::where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                // A. Pastikan key tidak dipakai oleh user lain
                if ((int) $existing->user_id !== (int) $user->id) {
                    return $this->respondError(
                        $request,
                        'Kunci transaksi tidak valid untuk akun Anda.',
                        422,
                        'idempotency_key'
                    );
                }

                // B. Pastikan parameter transaksi (campaign, nominal, is_anonim) cocok dengan key sebelumnya
                $requestIsAnonim = $request->boolean('is_anonim');
                if ((int) $existing->campaign_id !== (int) $campaign->id ||
                    (int) $existing->nominal !== (int) $validated['nominal'] ||
                    (bool) $existing->is_anonim !== $requestIsAnonim) {
                    return $this->respondError(
                        $request,
                        'Kunci transaksi telah digunakan dengan parameter donasi, nominal, atau opsi anonim yang berbeda.',
                        422,
                        'idempotency_key'
                    );
                }

                // C. Token lama hanya boleh dikembalikan jika Status API membuktikan transaksi pending milik record ini.
                if (!empty($existing->snap_token) && $existing->status === 'menunggu_pembayaran') {
                    try {
                        $remoteStatus = $midtransService->checkTransactionStatus($existing->order_id);
                        $remoteTx = $remoteStatus['transaction_status'] ?? null;

                        if ($remoteTx === 'settlement' || ($remoteTx === 'capture' && ($remoteStatus['fraud_status'] ?? '') === 'accept')) {
                            $reconciliation = $midtransService->reconcileDonation($existing);
                            $existing->refresh();
                            if ($reconciliation['reconciled'] && $existing->status === 'terverifikasi') {
                                return $this->respondError(
                                    $request,
                                    'Transaksi dengan Order ID ' . $existing->order_id . ' telah berhasil terverifikasi. Silakan periksa riwayat donasi Anda.',
                                    200,
                                    'info'
                                );
                            }
                            return $this->respondError($request, 'Konfirmasi pembayaran penyedia belum lengkap atau tidak valid. Silakan coba kembali.', 422, 'midtrans');
                        }

                        if (in_array($remoteTx, ['expire', 'deny', 'cancel'], true)) {
                            $reconciliation = $midtransService->reconcileDonation($existing);
                            $existing->refresh();
                            if ($existing->status === 'gagal') {
                                return $this->respondError(
                                    $request,
                                    'Transaksi sebelumnya telah kedaluwarsa atau dibatalkan oleh sistem pembayaran. Silakan lakukan donasi baru.',
                                    422,
                                    'idempotency_key'
                                );
                            }
                            return $this->respondError($request, 'Respons status transaksi gagal tidak lengkap atau tidak sesuai. Silakan lakukan pemeriksaan ulang.', 422, 'midtrans');
                        }

                        if ($midtransService->validateProviderPending($remoteStatus, $existing)) {
                            if ($request->expectsJson()) {
                                return response()->json([
                                    'success' => true,
                                    'snap_token' => $existing->snap_token,
                                    'snap_redirect_url' => $existing->snap_redirect_url,
                                    'order_id' => $existing->order_id,
                                    'message' => 'Transaksi pending yang sama ditemukan (idempotent).',
                                ]);
                            }

                            $donation = $existing;
                            $snapToken = $existing->snap_token;
                            return view('donatur.donasi-uang', compact('campaign', 'donation', 'snapToken'));
                        }

                        return $this->respondError(
                            $request,
                            'Status Snap transaksi lama belum dapat diverifikasi dari penyedia pembayaran. Silakan coba kembali tanpa mengulang order lama.',
                            422,
                            'idempotency_key'
                        );
                    } catch (MidtransConnectionException $e) {
                        return $this->respondError($request, 'Koneksi ke sistem pembayaran sedang lambat. Silakan coba kembali beberapa saat lagi.', 504, 'midtrans');
                    } catch (\Throwable $e) {
                        return $this->respondError($request, 'Status token pembayaran lama tidak dapat diverifikasi dengan aman.', 500, 'midtrans');
                    }
                }

                // D. Jika transaksi sebelumnya mengalami timeout tanpa token, periksa dulu kondisi transaksi di provider
                if ($existing->status === 'menunggu_pembayaran' && empty($existing->snap_token)) {
                    try {
                        $remoteStatus = $midtransService->checkTransactionStatus($existing->order_id);
                        $remoteTx = $remoteStatus['transaction_status'] ?? 'unknown';

                        if ($remoteTx === 'not_found') {
                            // 404 tidak membuktikan Snap session belum ada. Order lama tidak boleh digunakan kembali.
                            return $this->respondError(
                                $request,
                                'Status Order ID lama belum dapat ditemukan. Jangan ulangi pembayaran pada order lama; transaksi tersimpan untuk rekonsiliasi.',
                                422,
                                'idempotency_key'
                            );
                        } elseif ($remoteTx === 'settlement' || ($remoteTx === 'capture' && ($remoteStatus['fraud_status'] ?? '') === 'accept')) {
                            $reconciliation = $midtransService->reconcileDonation($existing);
                            $existing->refresh();
                            if ($reconciliation['reconciled'] && $existing->status === 'terverifikasi') {
                                return $this->respondError(
                                    $request,
                                    'Transaksi dengan Order ID ' . $existing->order_id . ' telah berhasil terverifikasi. Silakan periksa riwayat donasi Anda.',
                                    200,
                                    'info'
                                );
                            }
                            return $this->respondError($request, 'Konfirmasi settlement penyedia belum lengkap atau tidak valid.', 422, 'midtrans');
                        } elseif (in_array($remoteTx, ['expire', 'deny', 'cancel'])) {
                            $reconciliation = $midtransService->reconcileDonation($existing);
                            $existing->refresh();
                            if ($existing->status === 'gagal') {
                                return $this->respondError(
                                    $request,
                                    'Transaksi sebelumnya telah kedaluwarsa atau dibatalkan oleh sistem pembayaran. Silakan lakukan donasi baru.',
                                    422,
                                    'idempotency_key'
                                );
                            }
                            return $this->respondError($request, 'Respons status transaksi gagal tidak lengkap atau tidak sesuai.', 422, 'midtrans');
                        } else {
                            // Status provider pending atau unknown (ambigu): jangan membuat transaksi baru
                            return $this->respondError(
                                $request,
                                'Transaksi sebelumnya sedang diproses atau dalam verifikasi penyedia pembayaran (Order ID: ' . $existing->order_id . '). Silakan periksa status riwayat donasi Anda.',
                                422,
                                'idempotency_key'
                            );
                        }
                    } catch (MidtransConnectionException $e) {
                        return $this->respondError(
                            $request,
                            'Koneksi ke sistem pembayaran sedang lambat. Transaksi Anda telah tersimpan dengan Order ID: ' . $existing->order_id . '. Silakan periksa status riwayat atau coba beberapa saat lagi.',
                            504,
                            'midtrans'
                        );
                    } catch (\Throwable $e) {
                        return $this->respondError(
                            $request,
                            'Terjadi kendala saat memeriksa status pembayaran ke penyedia. Silakan coba kembali beberapa saat lagi.',
                            500,
                            'midtrans'
                        );
                    }
                }
            }
        }

        // 6. Buat record donasi uang baru
        $orderId = 'DON-' . time() . '-' . $campaign->id . '-' . strtoupper(Str::random(6));

        try {
            $donation = DB::transaction(function () use ($campaign, $user, $validated, $orderId, $idempotencyKey, $request) {
                return Donation::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'jenis' => 'uang',
                    'is_anonim' => $request->boolean('is_anonim'),
                    'nominal' => $validated['nominal'],
                    'is_recurring' => false,
                    'frequency' => null,
                    'order_id' => $orderId,
                    'idempotency_key' => $idempotencyKey ?: Str::uuid()->toString(),
                    'status' => 'menunggu_pembayaran',
                    'payment_status_detail' => 'initiating',
                ]);
            });
        
        } catch (QueryException $e) {
            // Handle duplicate idempotency key
            if ($e->getCode() == 23000 && $idempotencyKey) {
                $existing = Donation::where('idempotency_key', $idempotencyKey)->first();

                if ($existing && !empty($existing->snap_token)) {
                    $isOwner = (int) $existing->user_id === (int) $user->id;
                    $isCampaignMatch = (int) $existing->campaign_id === (int) $campaign->id;
                    $isNominalMatch = (int) $existing->nominal === (int) $validated['nominal'];
                    $isAnonimMatch = (bool) $existing->is_anonim === $request->boolean('is_anonim');
                    $isPending = $existing->status === 'menunggu_pembayaran';

                    if ($isOwner && $isCampaignMatch && $isNominalMatch && $isAnonimMatch && $isPending) {
                        try {
                            $remoteStatus = $midtransService->checkTransactionStatus($existing->order_id);
                            $remoteTx = $remoteStatus['transaction_status'] ?? null;

                            if ($remoteTx === 'settlement' || ($remoteTx === 'capture' && ($remoteStatus['fraud_status'] ?? '') === 'accept')) {
                                $reconciliation = $midtransService->reconcileDonation($existing);
                                $existing->refresh();
                                if ($reconciliation['reconciled'] && $existing->status === 'terverifikasi') {
                                    return $this->respondError($request, 'Transaksi telah berhasil terverifikasi oleh penyedia pembayaran.', 200, 'info');
                                }
                                return $this->respondError($request, 'Konfirmasi settlement penyedia belum lengkap atau tidak valid.', 422, 'midtrans');
                            }

                            if (in_array($remoteTx, ['expire', 'deny', 'cancel'], true)) {
                                $midtransService->reconcileDonation($existing);
                                $existing->refresh();
                                if ($existing->status === 'gagal') {
                                    return $this->respondError($request, 'Transaksi lama telah kedaluwarsa atau dibatalkan. Silakan lakukan donasi baru.', 422, 'idempotency_key');
                                }
                                return $this->respondError($request, 'Respons status transaksi gagal tidak lengkap atau tidak sesuai.', 422, 'midtrans');
                            }

                            if ($midtransService->validateProviderPending($remoteStatus, $existing)) {
                                if ($request->expectsJson()) {
                                    return response()->json([
                                        'success' => true,
                                        'snap_token' => $existing->snap_token,
                                        'snap_redirect_url' => $existing->snap_redirect_url,
                                        'order_id' => $existing->order_id,
                                    ]);
                                }

                                $donation = $existing;
                                $snapToken = $existing->snap_token;
                                return view('donatur.donasi-uang', compact('campaign', 'donation', 'snapToken'));
                            }
                        } catch (\Throwable $e) {
                            // Jangan mengembalikan token lama jika bukti provider tidak dapat diperoleh.
                        }
                    }
                }
            }

            return $this->respondError(
                $request,
                'Terjadi konflik transaksi bersamaan. Silakan muat ulang halaman dan coba kembali.',
                409,
                'idempotency_key'
            );
        }


        // 7. Request token Snap ke Midtrans Sandbox
        try {
            $snap = $midtransService->createSnapTransaction($donation, $user, $campaign);

            $donation->update([
                'snap_token' => $snap['token'],
                'snap_redirect_url' => $snap['redirect_url'],
                'payment_status_detail' => 'snap_created',
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'snap_token' => $donation->snap_token,
                    'snap_redirect_url' => $donation->snap_redirect_url,
                    'order_id' => $donation->order_id,
                ]);
            }

            $snapToken = $donation->snap_token;
            return view('donatur.donasi-uang', compact('campaign', 'donation', 'snapToken'));
        } catch (MidtransConnectionException $e) {
            // PERBAIKAN TIMEOUT: Jangan hapus record!
            // Pertahankan order_id untuk rekonsiliasi karena Midtrans mungkin sudah menerima order
            $donation->update([
                'payment_status_detail' => 'timeout_awaiting_reconciliation',
            ]);

            return $this->respondError(
                $request,
                'Koneksi ke gateway pembayaran sedang lambat. Transaksi Anda telah tercatat dengan Order ID: ' . $donation->order_id . '. Silakan periksa status riwayat atau coba beberapa saat lagi.',
                504,
                'midtrans'
            );
        } catch (MidtransConfigurationException $e) {
            // Kegagalan konfigurasi pasti sebelum request ke provider
            $donation->delete();

            return $this->respondError(
                $request,
                'Layanan pembayaran belum siap atau sedang dalam pemeliharaan.',
                503,
                'midtrans'
            );
        } catch (MidtransApiException $e) {
            // Kegagalan definitif dari Midtrans API (misal: 400 Bad Request)
            $donation->update([
                'payment_status_detail' => 'gateway_error',
                'status' => 'gagal',
            ]);

            return $this->respondError(
                $request,
                'Layanan pembayaran tidak dapat memproses donasi saat ini. Silakan coba kembali.',
                422,
                'midtrans'
            );
        } catch (\Throwable $e) {
            $donation->update([
                'payment_status_detail' => 'unexpected_error',
            ]);

            return $this->respondError(
                $request,
                'Terjadi kendala saat memproses donasi. Silakan coba kembali beberapa saat lagi.',
                500,
                'midtrans'
            );
        }
    }

    /**
     * Memverifikasi dan mengaktifkan Subscription setelah pembayaran awal 3DS selesai (E4.3.1).
     *
     * @param Request $request
     * @param string $orderId
     * @param MidtransPaymentService $midtransService
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function verifyActivation(Request $request, string $orderId, MidtransPaymentService $midtransService)
    {
        $user = getAuthUser();
        abort_unless($user && $user->role === 'donatur', 403);

        $donation = Donation::where('order_id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$donation) {
            return $this->respondError($request, 'Transaksi donasi tidak ditemukan.', 404, 'order_id');
        }

        $activation = $midtransService->activateSubscriptionFromInitialCharge($donation);
        if (!$activation['success']) {
            $status = ($activation['recoverable'] ?? false) ? 200 : 422;
            return $this->respondError($request, $activation['message'], $status, 'midtrans');
        }

        $subscription = $activation['subscription'];

        if ($request->expectsJson()) {
            return response()->json([
                'success'           => true,
                'subscription_id'   => $subscription->subscription_id,
                'status'            => $subscription->status,
                'frequency'         => $subscription->frequency,
                'nominal'           => (int) $subscription->nominal,
                'next_execution_at' => $subscription->next_execution_at,
                'message'           => 'Donasi rutin berhasil diaktifkan.',
            ], 200);
        }

        return redirect()->route('donatur.riwayat')->with('success', 'Donasi rutin berhasil diaktifkan.');
    }

    /**
     * Mengembalikan pesan error yang aman tanpa membocorkan credential atau raw SQL.
     */
    protected function respondError(Request $request, string $message, int $statusCode = 422, string $errorKey = 'error')
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $statusCode);
        }

        return back()->withInput()->withErrors([
            $errorKey => $message,
        ]);
    }
}
