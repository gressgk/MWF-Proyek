<?php

namespace App\Http\Controllers\Donatur;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\MidtransApiException;
use App\Services\MidtransConfigurationException;
use App\Services\MidtransConnectionException;
use App\Services\MidtransPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // 1. Validasi campaign aktif dan menerima uang
        abort_unless(
            $campaign->status === 'aktif' && in_array($campaign->tipe_bantuan, ['uang', 'keduanya']),
            404
        );

        // 2. Validasi autentikasi donatur
        $user = getAuthUser() ?? auth()->user();
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

        // 4. Penanganan pilihan recurring (Donasi Rutin)
        // Subscription API baru dikerjakan pada E4. Cegah diproses sebagai one-time payment.
        if ($request->boolean('is_recurring')) {
            return $this->respondError(
                $request,
                'Fitur donasi rutin saat ini sedang dalam persiapan integrasi Midtrans Subscription API (Fase E4). Silakan gunakan donasi satu kali terlebih dahulu.',
                422,
                'is_recurring'
            );
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

                // C. Jika token sebelumnya sudah ada dan masih berlaku (< 24 jam)
                if (!empty($existing->snap_token) && $existing->status === 'menunggu_pembayaran') {
                    $isTokenValid = $existing->created_at && $existing->created_at->gt(now()->subHours(24));

                    if ($isTokenValid) {
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
                }

                // D. Jika transaksi sebelumnya mengalami timeout tanpa token, periksa dulu kondisi transaksi di provider
                if ($existing->status === 'menunggu_pembayaran' && empty($existing->snap_token)) {
                    try {
                        $remoteStatus = $midtransService->checkTransactionStatus($existing->order_id);
                        $remoteTx = $remoteStatus['transaction_status'] ?? 'unknown';

                        if ($remoteTx === 'not_found') {
                            // Midtrans belum menerima order_id ini, aman untuk mencoba membuat Snap transaction
                            $snap = $midtransService->createSnapTransaction($existing, $user, $campaign);
                            $existing->update([
                                'snap_token' => $snap['token'],
                                'snap_redirect_url' => $snap['redirect_url'],
                                'payment_status_detail' => 'snap_created',
                            ]);

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
                        } elseif ($remoteTx === 'settlement' || ($remoteTx === 'capture' && ($remoteStatus['fraud_status'] ?? '') === 'accept')) {
                            // Pembayaran sudah terverifikasi di provider! Rekonsiliasi donasi
                            $midtransService->reconcileDonation($existing);
                            return $this->respondError(
                                $request,
                                'Transaksi dengan Order ID ' . $existing->order_id . ' telah berhasil terverifikasi. Silakan periksa riwayat donasi Anda.',
                                200,
                                'info'
                            );
                        } elseif (in_array($remoteTx, ['expire', 'deny', 'cancel'])) {
                            $existing->update([
                                'status' => 'gagal',
                                'payment_status_detail' => 'payment_' . $remoteTx,
                            ]);
                            return $this->respondError(
                                $request,
                                'Transaksi sebelumnya telah kedaluwarsa atau dibatalkan oleh sistem pembayaran. Silakan lakukan donasi baru.',
                                422,
                                'idempotency_key'
                            );
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
            // Race condition: dua request simultan dengan key yang sama membentur unique constraint
            if ($e->getCode() == 23000 && $idempotencyKey) {
                $existing = Donation::where('idempotency_key', $idempotencyKey)->first();
                if ($existing && !empty($existing->snap_token)) {
                    // Validasi ownership, campaign, nominal, is_anonim, dan status pending sebelum mengembalikan token
                    $isOwner = (int) $existing->user_id === (int) $user->id;
                    $isCampaignMatch = (int) $existing->campaign_id === (int) $campaign->id;
                    $isNominalMatch = (int) $existing->nominal === (int) $validated['nominal'];
                    $isAnonimMatch = (bool) $existing->is_anonim === $request->boolean('is_anonim');
                    $isPending = $existing->status === 'menunggu_pembayaran';

                    if ($isOwner && $isCampaignMatch && $isNominalMatch && $isAnonimMatch && $isPending) {
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
