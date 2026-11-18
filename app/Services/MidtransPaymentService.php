<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransConfigurationException extends \RuntimeException {}
class MidtransConnectionException extends \RuntimeException {}
class MidtransApiException extends \RuntimeException
{
    protected int $statusCode;

    public function __construct(string $message, int $statusCode = 0, ?\Throwable $previous = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}

class MidtransPaymentService
{
    protected ?string $serverKey;
    protected ?string $clientKey;
    protected bool $isProduction;
    protected string $apiUrl;
    protected string $snapUrl;
    protected string $statusApiUrl;

    public function __construct()
    {
        $this->serverKey = config('services.midtrans.server_key');
        $this->clientKey = config('services.midtrans.client_key');
        $this->isProduction = (bool) config('services.midtrans.is_production', false);
        $this->apiUrl = config('services.midtrans.api_url', 'https://app.sandbox.midtrans.com/snap/v1');
        $this->snapUrl = config('services.midtrans.snap_url', 'https://app.sandbox.midtrans.com/snap/snap.js');
        $this->statusApiUrl = config('services.midtrans.status_api_url', 'https://api.sandbox.midtrans.com/v2');
    }

    public function getClientKey(): ?string
    {
        return $this->clientKey;
    }

    public function getSnapUrl(): string
    {
        return $this->snapUrl;
    }

    public function isConfigured(): bool
    {
        return !empty($this->serverKey);
    }

    /**
     * Membuat Snap Token melalui Midtrans Snap API.
     *
     * @param Donation $donation
     * @param User $user
     * @param Campaign $campaign
     * @return array{token: string, redirect_url: string}
     * @throws MidtransConfigurationException
     * @throws MidtransConnectionException
     * @throws MidtransApiException
     */
    public function createSnapTransaction(Donation $donation, User $user, Campaign $campaign): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi pada server.');
        }

        $grossAmount = (int) $donation->nominal;

        $payload = [
            'transaction_details' => [
                'order_id' => $donation->order_id,
                'gross_amount' => $grossAmount,
            ],
            'customer_details' => [
                'first_name' => $donation->is_anonim ? 'Donatur' : ($user->name ?? 'Donatur WeCare'),
                'email' => $user->email ?? 'donatur@wecare.test',
                'phone' => $user->phone ?? '',
            ],
            'item_details' => [
                [
                    'id' => 'CAMPAIGN-' . $campaign->id,
                    'price' => $grossAmount,
                    'quantity' => 1,
                    'name' => mb_strimwidth($campaign->judul, 0, 45, '...'),
                ],
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(15)
                ->post("{$this->apiUrl}/transactions", $payload);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorMessages = $response->json('error_messages');
                $summary = is_array($errorMessages) ? implode(', ', $errorMessages) : 'Gateway error';

                // Log ter-sanitasi tanpa credential/PII
                Log::error('Midtrans Snap API request returned non-success HTTP status', [
                    'order_id' => $donation->order_id,
                    'status_code' => $statusCode,
                    'summary' => $summary,
                ]);

                // 504 / 502 dari upstream proxy gateway dapat berarti status ambigu
                if (in_array($statusCode, [502, 504])) {
                    throw new MidtransConnectionException('Gerbang pembayaran mengalami timeout (upstream gateway). Status transaksi belum pasti.');
                }

                throw new MidtransApiException($summary, $statusCode);
            }

            $data = $response->json();

            if (empty($data['token'])) {
                Log::error('Midtrans Snap API returned empty token payload', [
                    'order_id' => $donation->order_id,
                ]);

                throw new MidtransApiException('Token pembayaran tidak diterima dari gateway.', 500);
            }

            return [
                'token' => $data['token'],
                'redirect_url' => $data['redirect_url'] ?? '',
            ];
        } catch (ConnectionException $e) {
            Log::error('Midtrans Snap API connection timeout / drop', [
                'order_id' => $donation->order_id,
            ]);

            throw new MidtransConnectionException(
                'Koneksi ke Midtrans mengalami batas waktu (timeout). Transaksi mungkin telah diterima oleh penyedia.',
                0,
                $e
            );
        }
    }

    /**
     * Memeriksa status transaksi langsung ke API Midtrans.
     * Tidak mengubah status database lokal (hanya querying status dari provider).
     *
     * @param string $orderId
     * @return array{order_id?: ?string, status_code?: ?string, transaction_status?: ?string, fraud_status?: ?string, gross_amount?: ?string}
     * @throws MidtransConfigurationException
     * @throws MidtransConnectionException
     */
    public function checkTransactionStatus(string $orderId): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi.');
        }

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Accept' => 'application/json',
                ])
                ->timeout(10)
                ->get("{$this->statusApiUrl}/{$orderId}/status");

            if ($response->status() === 404) {
                return [
                    'order_id'           => $orderId,
                    'status_code'        => '404',
                    'transaction_status' => 'not_found',
                ];
            }

            if ($response->failed()) {
                Log::warning('Midtrans Status API returned error', [
                    'order_id'    => $orderId,
                    'status_code' => $response->status(),
                ]);

                return [
                    'order_id'           => $orderId,
                    'status_code'        => (string) $response->status(),
                    'transaction_status' => 'unknown',
                ];
            }

            $data = $response->json();

            return [
                'order_id'           => $data['order_id'] ?? null,
                'status_code'        => isset($data['status_code']) ? (string) $data['status_code'] : null,
                'transaction_status' => $data['transaction_status'] ?? null,
                'fraud_status'       => $data['fraud_status'] ?? null,
                'gross_amount'       => $data['gross_amount'] ?? null,
            ];
        } catch (ConnectionException $e) {
            Log::error('Midtrans Status API connection timeout', [
                'order_id' => $orderId,
            ]);

            throw new MidtransConnectionException('Koneksi status Midtrans mengalami timeout.', 0, $e);
        }
    }

    /**
     * Memvalidasi bahwa response Get Status API dari Midtrans lengkap, valid,
     * dan membuktikan pembayaran berhasil secara definitif.
     */
    public function validateProviderSettlement(array $remoteStatus, Donation $donation): bool
    {
        // 1. Validasi order_id
        if (empty($remoteStatus['order_id']) || $remoteStatus['order_id'] !== $donation->order_id) {
            return false;
        }

        // 2. Validasi gross_amount: wajib ada dan cocok eksak dengan nominal database (tanpa fallback!)
        if (empty($remoteStatus['gross_amount']) || !$this->isExactAmountMatch($remoteStatus['gross_amount'], (int) $donation->nominal)) {
            return false;
        }

        // 3. Validasi status_code: wajib ada dan konsisten
        $tx = $remoteStatus['transaction_status'] ?? '';
        if (empty($remoteStatus['status_code']) || !$this->isStatusCodeConsistent($remoteStatus['status_code'], $tx)) {
            return false;
        }

        // 4. Validasi status pembayaran: settlement atau capture (accept)
        if ($tx === 'settlement') {
            return true;
        }

        if ($tx === 'capture' && ($remoteStatus['fraud_status'] ?? '') === 'accept') {
            return true;
        }

        return false;
    }

    /**
     * Membandingkan nilai gross_amount dari provider dengan nominal integer di database
     * secara eksak tanpa pembulatan float, dan menolak pecahan non-nol.
     */
    public function isExactAmountMatch(string|int|float $grossAmount, int $dbNominal): bool
    {
        $amountStr = trim((string) $grossAmount);
        if (!is_numeric($amountStr)) {
            return false;
        }

        if (str_contains($amountStr, '.')) {
            [$intPart, $decPart] = explode('.', $amountStr, 2);
            // Jika ada pecahan selain .0 atau .00, tolak
            if ($decPart !== '00' && $decPart !== '0' && $decPart !== '') {
                return false;
            }
            return (int) $intPart === $dbNominal;
        }

        return (int) $amountStr === $dbNominal;
    }

    /**
     * Memeriksa konsistensi antara status_code dan transaction_status sesuai standar Midtrans.
     */
    public function isStatusCodeConsistent(string $statusCode, string $transactionStatus): bool
    {
        $validMap = [
            'settlement'     => ['200'],
            'capture'        => ['200'],
            'pending'        => ['201', '200'],
            'deny'           => ['202', '200'],
            'expire'         => ['407', '200'],
            'cancel'         => ['200', '202'],
            'refund'         => ['200'],
            'partial_refund' => ['200'],
            'chargeback'     => ['200'],
        ];

        if (!isset($validMap[$transactionStatus])) {
            return false;
        }

        return in_array($statusCode, $validMap[$transactionStatus], true);
    }

    /**
     * Memproses transisi status transaksi donasi secara atomik dengan row lock.
     * Digunakan bersama oleh MidtransWebhookController dan reconcileDonation().
     * CATATAN E3.2: Bebas dari HTTP call di dalam lock!
     */
    public function processTransactionTransition(
        Donation $donation,
        string $transactionStatus,
        ?string $fraudStatus = null,
        ?string $grossAmount = null,
        ?CampaignProgressService $progressService = null,
        bool $isPreVerifiedRecovery = false
    ): array {
        if ($donation->jenis !== 'uang') {
            return [
                'status' => 'ignored',
                'donation_status' => $donation->status,
                'message' => 'Hanya donasi uang yang diproses melalui sistem pembayaran.',
            ];
        }

        $progressService = $progressService ?? app(CampaignProgressService::class);

        return DB::transaction(function () use (
            $donation, $transactionStatus, $fraudStatus, $grossAmount, $progressService, $isPreVerifiedRecovery
        ) {
            // 1. Lock record donation untuk mencegah race condition (BEBAS DARI HTTP CALL DI DALAM LOCK!)
            $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
            if (!$locked) {
                return ['status' => 'not_found', 'donation_status' => 'unknown', 'message' => 'Record not found'];
            }

            // 2. Validasi exact nominal jika grossAmount disertakan
            if ($grossAmount !== null) {
                if (!$this->isExactAmountMatch($grossAmount, (int) $locked->nominal)) {
                    Log::error('Midtrans status transition: nominal tidak cocok', [
                        'order_id' => $locked->order_id,
                        'db_nominal' => $locked->nominal,
                        'gross_amount' => $grossAmount,
                    ]);
                    return [
                        'status' => 'amount_mismatch',
                        'donation_status' => $locked->status,
                        'message' => 'Nominal provider tidak cocok dengan database.',
                    ];
                }
            }

            // 3. Status Terverifikasi adalah Final
            if ($locked->status === 'terverifikasi') {
                $finalUpdate = [
                    'webhook_processed_at' => now(),
                    'webhook_transaction_status' => $transactionStatus,
                    'webhook_fraud_status' => $fraudStatus,
                ];

                if (in_array($transactionStatus, ['refund', 'partial_refund', 'chargeback'])) {
                    $finalUpdate['payment_status_detail'] = $transactionStatus;
                }

                $locked->update($finalUpdate);

                return [
                    'status' => 'already_final',
                    'donation_status' => $locked->status,
                    'message' => 'Donasi sudah terverifikasi sebelumnya.',
                ];
            }

            // 4. Out-of-order recovery: Jika saat ini 'gagal' tapi sudah diverifikasi oleh provider sebelum transaksi DB
            if ($locked->status === 'gagal') {
                if ($isPreVerifiedRecovery) {
                    $locked->update([
                        'status' => 'terverifikasi',
                        'payment_status_detail' => 'settled',
                        'webhook_processed_at' => now(),
                        'webhook_transaction_status' => $transactionStatus,
                        'webhook_fraud_status' => $fraudStatus,
                    ]);

                    $campaign = Campaign::find($locked->campaign_id);
                    if ($campaign) {
                        $progressService->recalculate($campaign);
                    }

                    Log::info('Midtrans: Out-of-order settlement verified after prior failure', [
                        'order_id' => $locked->order_id,
                    ]);

                    return [
                        'status' => 'recovered_settlement',
                        'donation_status' => 'terverifikasi',
                        'message' => 'Status berhasil dipulihkan menjadi terverifikasi berdasarkan konfirmasi resmi provider.',
                    ];
                }

                $locked->update([
                    'webhook_processed_at' => now(),
                    'webhook_transaction_status' => $transactionStatus,
                    'webhook_fraud_status' => $fraudStatus,
                ]);

                return [
                    'status' => 'already_failed',
                    'donation_status' => $locked->status,
                    'message' => 'Donasi sudah berstatus gagal.',
                ];
            }

            // 5. Transisi dari 'menunggu_pembayaran'
            $newStatus = null;
            $newDetail = null;

            if ($transactionStatus === 'settlement') {
                $newStatus = 'terverifikasi';
                $newDetail = 'settled';
            } elseif ($transactionStatus === 'capture') {
                if ($fraudStatus === 'accept') {
                    $newStatus = 'terverifikasi';
                    $newDetail = 'settled';
                } elseif ($fraudStatus === 'challenge') {
                    // Fraud challenge: jangan ubah ke gagal, pertahankan status menunggu_pembayaran
                    $newStatus = 'menunggu_pembayaran';
                    $newDetail = 'payment_challenge';
                } elseif ($fraudStatus === 'deny') {
                    $newStatus = 'gagal';
                    $newDetail = 'payment_deny';
                }
            } elseif (in_array($transactionStatus, ['expire', 'deny', 'cancel'])) {
                $newStatus = 'gagal';
                $newDetail = 'payment_' . $transactionStatus;
            } elseif ($transactionStatus === 'pending') {
                $newStatus = 'menunggu_pembayaran';
                $newDetail = 'pending';
            }

            $updateData = [
                'webhook_processed_at' => now(),
                'webhook_transaction_status' => $transactionStatus,
                'webhook_fraud_status' => $fraudStatus,
            ];

            if ($newStatus !== null) {
                $updateData['status'] = $newStatus;
            }
            if ($newDetail !== null) {
                $updateData['payment_status_detail'] = $newDetail;
            }

            $locked->update($updateData);

            if ($newStatus === 'terverifikasi') {
                $campaign = Campaign::find($locked->campaign_id);
                if ($campaign) {
                    $progressService->recalculate($campaign);
                }
            }

            return [
                'status' => 'processed',
                'donation_status' => $locked->status,
                'message' => 'Status berhasil diperbarui.',
            ];
        });
    }

    /**
     * Rekonsiliasi status donasi melalui Midtrans Status API secara aman.
     * Menggunakan transisi status dan aturan bisnis yang identik dengan webhook.
     */
    public function reconcileDonation(Donation $donation, ?CampaignProgressService $progressService = null): array
    {
        if ($donation->jenis !== 'uang') {
            return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'not_money_donation'];
        }

        // Status final tidak boleh diubah lagi secara sembarangan
        if (in_array($donation->status, ['terverifikasi', 'gagal'])) {
            return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'already_final'];
        }

        // HTTP request ke Status API di luar database transaction (NO LOCK HELD)
        $midtransStatus = $this->checkTransactionStatus($donation->order_id);
        $transactionStatus = $midtransStatus['transaction_status'] ?? null;
        $fraudStatus = $midtransStatus['fraud_status'] ?? null;

        if ($transactionStatus === 'not_found') {
            return ['reconciled' => true, 'status' => $donation->status, 'detail' => 'order_not_found_on_provider'];
        }

        if (empty($transactionStatus)) {
            return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'unknown_transaction_status'];
        }

        // Untuk settlement / capture accept, WAJIB validasi provider data secara lengkap
        // (order_id, gross_amount, status_code, fraud_status). Tanpa gross_amount -> DITOLAK!
        if ($transactionStatus === 'settlement' || ($transactionStatus === 'capture' && $fraudStatus === 'accept')) {
            if (!empty($midtransStatus['gross_amount']) && !$this->isExactAmountMatch($midtransStatus['gross_amount'], (int) $donation->nominal)) {
                Log::error('Midtrans reconciliation: nominal tidak cocok', [
                    'order_id' => $donation->order_id,
                    'db_nominal' => $donation->nominal,
                    'provider_amount' => $midtransStatus['gross_amount'],
                ]);
                return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'amount_mismatch'];
            }

            if (!$this->validateProviderSettlement($midtransStatus, $donation)) {
                Log::error('Midtrans reconciliation: data provider tidak lengkap atau tidak valid', [
                    'order_id' => $donation->order_id,
                    'remote' => $midtransStatus,
                ]);
                return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'incomplete_or_mismatched_provider_data'];
            }
        }

        // Validasi order_id untuk status lain
        if (!empty($midtransStatus['order_id']) && $midtransStatus['order_id'] !== $donation->order_id) {
            return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'order_id_mismatch'];
        }

        $grossAmount = $midtransStatus['gross_amount'] ?? null;

        $result = $this->processTransactionTransition(
            $donation,
            $transactionStatus,
            $fraudStatus,
            $grossAmount,
            $progressService
        );

        return [
            'reconciled' => in_array($result['status'], ['processed', 'already_final', 'recovered_settlement']),
            'status' => $result['donation_status'],
            'detail' => $result['status'],
        ];
    }
}
