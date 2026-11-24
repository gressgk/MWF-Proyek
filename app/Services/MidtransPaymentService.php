<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Subscription;
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
    protected string $subscriptionApiUrl;

    public const SUPPORTED_RECURRING_PAYMENTS = ['credit_card', 'gopay'];

    public function __construct()
    {
        $this->serverKey = config('services.midtrans.server_key');
        $this->clientKey = config('services.midtrans.client_key');
        $this->isProduction = (bool) config('services.midtrans.is_production', false);
        $this->apiUrl = config('services.midtrans.api_url', 'https://app.sandbox.midtrans.com/snap/v1');
        $this->snapUrl = config('services.midtrans.snap_url', 'https://app.sandbox.midtrans.com/snap/snap.js');
        $this->statusApiUrl = config('services.midtrans.status_api_url', 'https://api.sandbox.midtrans.com/v2');
        $this->subscriptionApiUrl = config('services.midtrans.subscription_api_url', 'https://api.sandbox.midtrans.com/v1/subscriptions');
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
     * Memeriksa apakah tipe pembayaran didukung untuk donasi rutin (recurring).
     * Catatan E4.1: GoPay memerlukan alur GoPay Account Linking (pay_account API)
     * yang belum terintegrasi di platform, sehingga saat ini hanya 'credit_card'
     * yang diaktifkan untuk pembuatan donasi rutin baru.
     */
    public function isPaymentTypeSupportedForRecurring(?string $paymentType): bool
    {
        if (empty($paymentType)) {
            return false;
        }
        $type = strtolower($paymentType);
        if ($type === 'gopay') {
            return false;
        }
        return in_array($type, self::SUPPORTED_RECURRING_PAYMENTS, true);
    }

    /**
     * Membuat langganan donasi rutin melalui Midtrans Subscription API.
     *
     * @param User $user
     * @param Campaign $campaign
     * @param int $nominal
     * @param string $frequency 'mingguan' atau 'bulanan'
     * @param string $paymentType 'credit_card' atau 'gopay'
     * @param string $token Token kartu kredit berulang / saved_token_id / tokenisasi akun
     * @param bool $isAnonim
     * @param string|null $idempotencyKey
     * @return array
     * @throws MidtransConfigurationException
     * @throws MidtransApiException
     * @throws MidtransConnectionException
     */
    public function createSubscription(
        User $user,
        Campaign $campaign,
        int $nominal,
        string $frequency,
        string $paymentType,
        string $token,
        bool $isAnonim = false,
        ?string $idempotencyKey = null,
        array $options = []
    ): array {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi pada server.');
        }

        $paymentType = strtolower($paymentType);
        if ($paymentType === 'gopay') {
            $gopayAccountId = $options['gopay_account_id'] ?? null;
            if (empty($gopayAccountId)) {
                throw new MidtransApiException("Metode pembayaran GoPay donasi rutin memerlukan GoPay Account Linking (gopay.account_id) yang belum tersedia pada platform. Silakan gunakan kartu kredit/debit.", 422);
            }
        } elseif (!$this->isPaymentTypeSupportedForRecurring($paymentType)) {
            throw new MidtransApiException("Metode pembayaran '{$paymentType}' tidak mendukung donasi rutin. Hanya credit_card yang didukung saat ini.", 422);
        }

        $frequency = strtolower($frequency);
        if (!in_array($frequency, ['mingguan', 'bulanan'], true)) {
            throw new MidtransApiException("Frekuensi donasi rutin '{$frequency}' tidak valid. Pilih mingguan atau bulanan.", 422);
        }

        if (empty($token)) {
            throw new MidtransApiException("Token pembayaran langganan wajib diisi.", 422);
        }

        // Jangan izinkan nomor kartu mentah digunakan sebagai token langganan
        if (preg_match('/^\d{13,19}$/', str_replace([' ', '-'], '', $token))) {
            throw new MidtransApiException("Nomor kartu mentah dilarang digunakan langsung sebagai token langganan. Gunakan saved_token_id resmi.", 422);
        }

        $intervalUnit = ($frequency === 'mingguan') ? 'week' : 'month';
        $interval = 1;

        // Midtrans Subscription name: max 40 karakter, hanya alfanumerik, dash (-), underscore (_), tilde (~), titik (.)
        // Dilarang spasi, titik dua, atau karakter spesial lain. Midtrans menggunakan name ini sebagai prefix order_id transaksi berulang.
        $cleanSuffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $name = "SUB-{$campaign->id}-{$user->id}-{$cleanSuffix}";
        $name = substr(preg_replace('/[^a-zA-Z0-9\-_~.]/', '', $name), 0, 40);

        // Pencegahan double charge: jika start_time ditentukan dalam options (misal 1 periode di masa depan setelah initial charge), gunakan itu
        $startTime = $options['start_time'] ?? now()->format('Y-m-d H:i:s O');

        $payload = [
            'name' => $name,
            'amount' => (string) $nominal,
            'currency' => 'IDR',
            'payment_type' => $paymentType,
            'token' => $token,
            'schedule' => [
                'interval' => $interval,
                'interval_unit' => $intervalUnit,
                'start_time' => $startTime,
            ],
            'metadata' => [
                'user_id' => $user->id,
                'campaign_id' => $campaign->id,
                'frequency' => $frequency,
                'is_anonim' => $isAnonim,
                'idempotency_key' => $idempotencyKey,
            ],
            'customer_details' => [
                'first_name' => $isAnonim ? 'Donatur' : ($user->name ?? 'Donatur WeCare'),
                'email' => $user->email ?? 'donatur@wecare.test',
            ],
        ];

        if ($paymentType === 'gopay' && !empty($gopayAccountId)) {
            $payload['gopay'] = ['account_id' => $gopayAccountId];
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($idempotencyKey)) {
            // Midtrans Idempotency-Key header: max 36 karakter (format UUID)
            $headers['Idempotency-Key'] = substr(trim($idempotencyKey), 0, 36);
        }

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders($headers)
                ->timeout(15)
                ->post($this->subscriptionApiUrl, $payload);

            if ($response->failed()) {
                $statusCode = $response->status();
                $errorMessages = $response->json('error_messages') ?? $response->json('message');
                $summary = is_array($errorMessages) ? implode(', ', $errorMessages) : (string) $errorMessages;
                if (empty($summary)) {
                    $summary = 'Subscription gateway error';
                }

                Log::error('Midtrans Subscription API request failed', [
                    'campaign_id' => $campaign->id,
                    'status_code' => $statusCode,
                    'summary' => $summary,
                ]);

                if (in_array($statusCode, [502, 504])) {
                    throw new MidtransConnectionException('Gerbang pembayaran langganan mengalami timeout.');
                }

                throw new MidtransApiException($summary, $statusCode);
            }

            $data = $response->json();
            if (empty($data['id'])) {
                throw new MidtransApiException('ID langganan tidak diterima dari gateway.', 500);
            }

            return $data;
        } catch (ConnectionException $e) {
            Log::error('Midtrans Subscription API connection error', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
            throw new MidtransConnectionException('Koneksi ke gateway langganan Midtrans terputus.');
        }
    }

    /**
     * Memproses otorisasi transaksi awal kartu kredit untuk mendapatkan saved_token_id (One Click / Recurring).
     *
     * @param Donation $donation
     * @param string $cardTokenId Temporary token_id dari MidtransNew3ds
     * @param User $user
     * @param Campaign $campaign
     * @return array
     * @throws MidtransConfigurationException
     * @throws MidtransApiException
     * @throws MidtransConnectionException
     */
    public function chargeInitialCardWithSaveToken(Donation $donation, string $cardTokenId, User $user, Campaign $campaign): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi pada server.');
        }

        $chargeUrl = ($this->isProduction ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2') . '/charge';

        $payload = [
            'payment_type' => 'credit_card',
            'transaction_details' => [
                'order_id'     => $donation->order_id,
                'gross_amount' => (int) $donation->nominal,
            ],
            'credit_card' => [
                'token_id'       => $cardTokenId,
                'save_token_id'  => true,
                'authentication' => true,
            ],
            'customer_details' => [
                'first_name' => $donation->is_anonim ? 'Donatur' : ($user->name ?? 'Donatur WeCare'),
                'email'      => $user->email ?? 'donatur@wecare.test',
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(15)
                ->post($chargeUrl, $payload);

            if ($response->failed()) {
                $status = $response->status();
                $err = $response->json('status_message') ?? $response->json('message') ?? 'Charge failure';
                if (in_array($status, [400, 412], true) || str_contains(strtolower((string)$err), 'one click') || str_contains(strtolower((string)$err), 'save_token_id')) {
                    throw new MidtransApiException('Fitur pembayaran berulang (One Click / Saved Card) belum aktif pada akun merchant Midtrans. Hubungi pengelola platform.', 422);
                }
                throw new MidtransApiException("Gagal memproses otorisasi kartu: {$err}", $status);
            }

            return $response->json();
        } catch (ConnectionException $e) {
            throw new MidtransConnectionException('Koneksi ke gateway pembayaran kartu terputus.');
        }
    }

    /**
     * Mengaktifkan langganan (Subscription) dari pembayaran awal kartu kredit yang berhasil (E4.3.1).
     *
     * @param Donation $donation Record donasi pembayaran awal (INIT-SUB-...)
     * @param string|null $savedTokenId Token saved_token_id jika sudah diperoleh dari webhook/charge payload
     * @return array{success: bool, subscription: ?Subscription, message: string, recoverable?: bool}
     */
    public function activateSubscriptionFromInitialCharge(
        Donation $donation,
        ?string $savedTokenId = null
    ): array {
        if (empty($donation->frequency) || !in_array($donation->frequency, ['mingguan', 'bulanan'], true)) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Bukan transaksi donasi rutin.',
            ];
        }

        // 1. Cek idempotency awal jika subscription_id sudah ada pada donasi
        if (!empty($donation->subscription_id)) {
            $existingSub = Subscription::where('subscription_id', $donation->subscription_id)->first();
            // Hanya jalur pemulihan provider untuk subscription_id yang sudah ada.
            // Kegagalan Get Subscription WAJIB fail-closed; tidak boleh jatuh ke Create API.
            try {
                $remoteSub = $this->getSubscription($donation->subscription_id);
                $remoteIdentity = $remoteSub['id'] ?? null;
                $remoteAmount = $remoteSub['amount'] ?? null;
                $remoteCurrency = $remoteSub['currency'] ?? null;
                $remoteStatus = $remoteSub['status'] ?? null;
                $remoteSchedule = $remoteSub['schedule'] ?? null;
                $remoteIntervalUnit = is_array($remoteSchedule)
                    ? ($remoteSchedule['interval_unit'] ?? null)
                    : null;
                $remoteInterval = is_array($remoteSchedule)
                    ? ($remoteSchedule['interval'] ?? null)
                    : null;
                $expectedIntervalUnit = $donation->frequency === 'mingguan' ? 'week' : 'month';
                $identityMatches = $remoteIdentity === $donation->subscription_id;
                $amountMatches = !empty($remoteAmount)
                    && $this->isExactAmountMatch($remoteAmount, (int) $donation->nominal);
                $currencyMatches = !empty($remoteCurrency)
                    && strtoupper((string) $remoteCurrency) === 'IDR';
                $scheduleMatches = $remoteInterval === 1
                    && $remoteIntervalUnit === $expectedIntervalUnit;
                $statusMatches = in_array(strtolower((string) $remoteStatus), ['active', 'pending'], true);

                if ($identityMatches && $amountMatches && $currencyMatches && $scheduleMatches && $statusMatches) {
                    $intervalUnit  = ($donation->frequency === 'mingguan') ? 'week' : 'month';
                    $nextCycleTime = ($donation->frequency === 'mingguan') ? now()->addWeek() : now()->addMonth();

                    $providerToken = $remoteSub['saved_token_id'] ?? null;
                    $providerToken = is_string($providerToken) ? trim($providerToken) : null;
                    if (
                        $providerToken === '' ||
                        ($providerToken !== null &&
                            preg_match('/^\d{13,19}$/', str_replace([' ', '-'], '', $providerToken)) === 1)
                    ) {
                        $providerToken = null;
                    }

                    $metadata = $remoteSub['metadata'] ?? [];
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }
                    if ($providerToken === null) {
                        $metadata['token_status'] = 'missing_saved_token';
                    } else {
                        $metadata['token_status'] = 'verified_saved_token';
                    }

                    $attributes = [
                        'nominal'           => (int) ($remoteSub['amount'] ?? $donation->nominal),
                        'frequency'         => $donation->frequency,
                        'interval'          => (int) ($remoteSchedule['interval'] ?? 1),
                        'interval_unit'     => $intervalUnit,
                        'payment_type'      => strtolower((string) ($remoteSub['payment_type'] ?? 'credit_card')),
                        'status'            => strtolower((string) $remoteStatus),
                        'is_anonim'         => (bool) $donation->is_anonim,
                        'idempotency_key'   => $remoteSub['metadata']['idempotency_key'] ?? $donation->idempotency_key,
                        'next_execution_at' => isset($remoteSub['schedule']['next_execution_at'])
                            ? \Carbon\Carbon::parse($remoteSub['schedule']['next_execution_at'])
                            : $nextCycleTime,
                        'metadata'          => $metadata,
                    ];
                    if ($providerToken !== null) {
                        $attributes['token'] = $providerToken;
                    }

                    $sub = $existingSub;
                    if ($sub) {
                        $sub->update($attributes);
                    } else {
                        $sub = Subscription::create(array_merge([
                            'subscription_id' => $remoteSub['id'],
                            'user_id'         => $donation->user_id,
                            'campaign_id'     => $donation->campaign_id,
                        ], $attributes));
                    }

                    $donation->update([
                        'payment_status_detail' => $providerToken === null
                            ? 'subscription_recovered_missing_saved_token'
                            : 'subscription_activated',
                    ]);

                    return [
                        'success'      => true,
                        'subscription' => $sub,
                        'message'      => $providerToken === null
                            ? 'Langganan aktif berhasil dipulihkan; saved_token_id lokal belum tersedia dari penyedia.'
                            : 'Langganan berhasil dipulihkan dan disinkronkan dari penyedia.',
                    ];
                }

                Log::warning('Midtrans subscription provider identity mismatch during recovery', [
                    'requested_subscription_id' => $donation->subscription_id,
                    'provider_subscription_id'  => $remoteIdentity,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'message'      => 'Subscription provider tidak cocok dengan data Donation lokal. Recovery dihentikan untuk menghindari duplikasi.',
                ];
            } catch (\Throwable $e) {
                Log::error('Gagal memulihkan subscription dari provider', [
                    'subscription_id' => $donation->subscription_id,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'message'      => 'Subscription provider tidak dapat diverifikasi saat ini. Silakan coba reconciliation kembali.',
                ];
            }
        }

        // 2. Verifikasi status pembayaran awal ke provider (DILAKUKAN DI LUAR DATABASE TRANSACTION)
        $verifiedToken = $savedTokenId;
        if ($donation->status !== 'terverifikasi' || empty($verifiedToken)) {
            try {
                $remoteStatus = $this->checkTransactionStatus($donation->order_id);
            } catch (MidtransConnectionException $e) {
                Log::error('Timeout saat verifikasi status pembayaran awal ke provider', [
                    'order_id' => $donation->order_id,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'message'      => 'Koneksi ke penyedia pembayaran mengalami timeout saat memeriksa status transaksi.',
                ];
            } catch (\Throwable $e) {
                Log::error('API error saat verifikasi status pembayaran awal ke provider', [
                    'order_id' => $donation->order_id,
                    'error'    => $e->getMessage(),
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Status pembayaran awal tidak dapat diverifikasi dari penyedia.',
                ];
            }

            $remoteTxStatus = $remoteStatus['transaction_status'] ?? null;
            $remoteFraud    = $remoteStatus['fraud_status'] ?? null;
            $remoteAmount   = $remoteStatus['gross_amount'] ?? null;
            $remoteCurrency = $remoteStatus['currency'] ?? null;
            $remoteCode     = (string) ($remoteStatus['status_code'] ?? '');
            $remoteOrderId  = $remoteStatus['order_id'] ?? null;

            // A. Cek kecocokan order_id
            if (!empty($remoteOrderId) && $remoteOrderId !== $donation->order_id) {
                Log::warning('Midtrans initial charge order_id mismatch', [
                    'local_order_id'  => $donation->order_id,
                    'remote_order_id' => $remoteOrderId,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Identitas order pembayaran awal tidak sesuai.',
                ];
            }

            // B. Jika transaksi ditolak / gagal
            if (in_array($remoteTxStatus, ['deny', 'cancel', 'expire'], true) || $remoteFraud === 'deny') {
                DB::transaction(function () use ($donation) {
                    Donation::where('id', $donation->id)->update([
                        'status'                => 'gagal',
                        'payment_status_detail' => 'charge_denied',
                        'webhook_processed_at'  => now(),
                    ]);
                });
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Otorisasi pembayaran awal ditolak oleh penyedia.',
                ];
            }

            // C. Verifikasi settlement atau capture-accept dengan status_code 200
            $isRemoteSuccess = ($remoteTxStatus === 'settlement') || ($remoteTxStatus === 'capture' && $remoteFraud === 'accept');
            if (!$isRemoteSuccess || $remoteCode !== '200') {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Pembayaran awal belum berhasil terverifikasi dari penyedia.',
                ];
            }

            // D. Validasi nominal eksak
            if (empty($remoteAmount) || !$this->isExactAmountMatch($remoteAmount, (int) $donation->nominal)) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Nominal pembayaran awal tidak sesuai dengan data tagihan.',
                ];
            }

            // E. Validasi mata uang
            if (!empty($remoteCurrency) && strtoupper($remoteCurrency) !== 'IDR') {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Mata uang pembayaran awal bukan IDR.',
                ];
            }

            // F. Validasi saved_token_id dari provider
            $providerSavedToken = $remoteStatus['saved_token_id'] ?? null;
            if (empty($providerSavedToken)) {
                DB::transaction(function () use ($donation) {
                    Donation::where('id', $donation->id)->update([
                        'payment_status_detail' => 'missing_saved_token',
                    ]);
                });
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Fitur tokenisasi berulang (saved_token_id) belum aktif atau tidak dikembalikan oleh penyedia.',
                ];
            }

            // Jika ada token yang dikirim pemanggil, pastikan cocok dengan yang diterbitkan provider
            if (!empty($verifiedToken) && !hash_equals($providerSavedToken, $verifiedToken)) {
                Log::warning('Midtrans initial charge token mismatch with provider token', [
                    'order_id' => $donation->order_id,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Token pembayaran tidak terautentikasi oleh penyedia.',
                ];
            }

            $verifiedToken = $providerSavedToken;

            // Singkat & atomik: tandai donasi awal terverifikasi jika belum
            DB::transaction(function () use ($donation, $remoteTxStatus, $remoteFraud) {
                $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                if ($locked && $locked->status !== 'terverifikasi') {
                    $locked->update([
                        'status'                     => 'terverifikasi',
                        'payment_status_detail'      => 'settled',
                        'webhook_processed_at'       => now(),
                        'webhook_transaction_status' => $remoteTxStatus,
                        'webhook_fraud_status'       => $remoteFraud,
                        'tanggal_diterima'           => now(),
                    ]);
                    $campaign = Campaign::find($locked->campaign_id);
                    if ($campaign) {
                        app(CampaignProgressService::class)->recalculate($campaign);
                    }
                }
            });
        }

        if (empty($verifiedToken)) {
            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'missing_saved_token',
                ]);
            });
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Fitur tokenisasi berulang (saved_token_id) belum aktif atau tidak dikembalikan oleh penyedia.',
            ];
        }

        $user = User::find($donation->user_id);
        $campaign = Campaign::find($donation->campaign_id);
        if (!$user || !$campaign) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'User atau campaign tidak ditemukan.',
            ];
        }

        $intervalUnit  = ($donation->frequency === 'mingguan') ? 'week' : 'month';
        $nextCycleTime = ($donation->frequency === 'mingguan') ? now()->addWeek() : now()->addMonth();
        $startTimeStr  = $nextCycleTime->format('Y-m-d H:i:s O');

        $subIdempotencyKey = $donation->idempotency_key
            ? str_replace('init_', '', $donation->idempotency_key)
            : substr(hash('sha256', 'wecare-subscription|' . $donation->order_id), 0, 36);

        // 3. Cek jika Subscription dengan idempotency_key ini sudah dibuat
        $existingSub = Subscription::where('idempotency_key', $subIdempotencyKey)->first();
        if ($existingSub) {
            DB::transaction(function () use ($donation, $existingSub) {
                Donation::where('id', $donation->id)->update([
                    'subscription_id'       => $existingSub->subscription_id,
                    'payment_status_detail' => 'subscription_activated',
                ]);
            });
            return [
                'success'      => true,
                'subscription' => $existingSub,
                'message'      => 'Langganan sudah aktif (idempotent).',
            ];
        }

        // 4. State guard atomik untuk koordinasi concurrent request tanpa menahan row lock selama HTTP call
        $stateAcquired = DB::transaction(function () use ($donation) {
            $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
            if (!$locked) return 'not_found';
            if (!empty($locked->subscription_id)) return 'already_active';
            if ($locked->payment_status_detail === 'activation_in_progress') {
                $leaseStartedAt = $locked->updated_at;
                if ($leaseStartedAt && $leaseStartedAt->lte(now()->subMinutes(5))) {
                    $locked->update(['payment_status_detail' => 'activation_in_progress']);
                    return 'acquired';
                }
                return 'in_progress';
            }
            $locked->update(['payment_status_detail' => 'activation_in_progress']);
            return 'acquired';
        });

        if ($stateAcquired === 'not_found') {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Donasi awal tidak ditemukan.',
            ];
        }

        if ($stateAcquired === 'already_active') {
            $donation->refresh();
            $existingSub = Subscription::where('subscription_id', $donation->subscription_id)->first();
            if ($existingSub) {
                return [
                    'success'      => true,
                    'subscription' => $existingSub,
                    'message'      => 'Langganan sudah aktif (idempotent).',
                ];
            }
        }

        if ($stateAcquired === 'in_progress') {
            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => true,
                'message'      => 'Aktivasi langganan sedang diproses oleh request lain. Silakan lakukan reconciliation/retry tanpa menagih ulang.',
            ];
        }

        if ($stateAcquired !== 'acquired') {
            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => true,
                'message'      => 'Aktivasi langganan belum dapat diproses. Status dapat diverifikasi ulang.',
            ];
        }

        // 5. Eksekusi Create Subscription API DI LUAR DATABASE TRANSACTION
        try {
            $subResult = $this->createSubscription(
                $user,
                $campaign,
                (int) $donation->nominal,
                $donation->frequency,
                'credit_card',
                $verifiedToken,
                (bool) $donation->is_anonim,
                $subIdempotencyKey,
                ['start_time' => $startTimeStr]
            );
        } catch (MidtransConnectionException $e) {
            // Provider timeout: JANGAN sentuh status terverifikasi donasi, tandai recoverable
            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'subscription_activation_failed_recoverable',
                ]);
            });

            Log::error('Timeout saat createSubscription ke Midtrans (recoverable)', [
                'order_id' => $donation->order_id,
            ]);

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => true,
                'message'      => 'Koneksi ke gateway langganan mengalami timeout. Status pembayaran awal aman dan aktivasi dapat diverifikasi ulang.',
            ];
        } catch (\Throwable $e) {
            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'subscription_activation_failed_recoverable',
                ]);
            });

            Log::error('Gagal membuat langganan setelah initial charge berhasil (recoverable)', [
                'order_id'    => $donation->order_id,
                'donation_id' => $donation->id,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => true,
                'message'      => 'Pembayaran awal berhasil, namun pembuatan jadwal langganan otomatis mengalami kendala sementara. Status dapat dipulihkan tanpa penagihan ulang.',
            ];
        }

        // 6. Simpan record Subscription lokal secara atomik dalam transaksi database singkat
        try {
            $subscription = DB::transaction(function () use (
                $subResult, $donation, $user, $campaign, $verifiedToken,
                $intervalUnit, $nextCycleTime, $subIdempotencyKey
            ) {
                $sub = Subscription::where('subscription_id', $subResult['id'])->first();
                if (!$sub) {
                    $sub = Subscription::create([
                        'subscription_id'   => $subResult['id'],
                        'user_id'           => $user->id,
                        'campaign_id'       => $campaign->id,
                        'nominal'           => (int) $donation->nominal,
                        'frequency'         => $donation->frequency,
                        'interval'          => 1,
                        'interval_unit'     => $intervalUnit,
                        'payment_type'      => 'credit_card',
                        'token'             => $verifiedToken,
                        'status'            => $subResult['status'] ?? 'active',
                        'is_anonim'         => (bool) $donation->is_anonim,
                        'idempotency_key'   => $subIdempotencyKey,
                        'next_execution_at' => isset($subResult['schedule']['next_execution_at'])
                            ? \Carbon\Carbon::parse($subResult['schedule']['next_execution_at'])
                            : $nextCycleTime,
                        'metadata'          => $subResult['metadata'] ?? [],
                    ]);
                }

                Donation::where('id', $donation->id)->update([
                    'subscription_id'       => $sub->subscription_id,
                    'payment_status_detail' => 'subscription_activated',
                ]);

                return $sub;
            });

            Log::info('Midtrans Subscription activated successfully after initial charge', [
                'order_id'        => $donation->order_id,
                'subscription_id' => $subscription->subscription_id,
            ]);

            return [
                'success'      => true,
                'subscription' => $subscription,
                'message'      => 'Donasi rutin berhasil diaktifkan.',
            ];
        } catch (\Throwable $dbEx) {
            // Skenario: Provider create success, tapi penyimpanan lokal gagal
            Log::error('Gagal menyimpan subscription lokal setelah provider create berhasil', [
                'order_id'        => $donation->order_id,
                'subscription_id' => $subResult['id'] ?? null,
            ]);

            if (!empty($subResult['id'])) {
                try {
                    Donation::where('id', $donation->id)->update([
                        'subscription_id'       => $subResult['id'],
                        'payment_status_detail' => 'subscription_local_save_failed_recoverable',
                    ]);
                } catch (\Throwable $e2) {
                    // ignore
                }
            }

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => true,
                'message'      => 'Langganan berhasil dibuat di penyedia, sinkronisasi data lokal tertunda. Silakan verifikasi ulang.',
            ];
        }
    }

    /**
     * Mengambil detail langganan dari Midtrans.
     */
    public function getSubscription(string $subscriptionId): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi.');
        }

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(10)
                ->get("{$this->subscriptionApiUrl}/{$subscriptionId}");

            if ($response->failed()) {
                throw new MidtransApiException('Gagal mengambil data langganan dari Midtrans.', $response->status());
            }

            return $response->json();
        } catch (ConnectionException $e) {
            throw new MidtransConnectionException('Koneksi ke gateway Midtrans terputus.');
        }
    }

    public function disableSubscription(string $subscriptionId): array
    {
        return $this->mutateSubscription($subscriptionId, 'disable');
    }

    public function enableSubscription(string $subscriptionId): array
    {
        return $this->mutateSubscription($subscriptionId, 'enable');
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->mutateSubscription($subscriptionId, 'cancel');
    }

    protected function mutateSubscription(string $subscriptionId, string $operation): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi.');
        }
        if (!preg_match('/^[A-Za-z0-9\-_.]+$/', $subscriptionId)) {
            throw new MidtransApiException('Subscription ID tidak valid.', 422);
        }
        if (!in_array($operation, ['disable', 'enable', 'cancel'], true)) {
            throw new MidtransApiException('Operasi subscription tidak valid.', 422);
        }

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(15)
                ->post("{$this->subscriptionApiUrl}/{$subscriptionId}/{$operation}");

            if ($response->status() === 401 || $response->status() === 403) {
                throw new MidtransApiException('Otorisasi provider subscription ditolak.', $response->status());
            }
            if ($response->status() === 404) {
                throw new MidtransApiException('Subscription tidak ditemukan di provider.', 404);
            }
            if ($response->failed()) {
                throw new MidtransApiException('Operasi subscription provider gagal.', $response->status());
            }

            $data = $response->json();
            $payload = is_array($data) ? $data : [];
            $payload['_http_status'] = $response->status();
            return $payload;
        } catch (ConnectionException $e) {
            throw new MidtransConnectionException('Koneksi ke provider subscription mengalami timeout.');
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
                    'transaction_id'     => null,
                    'order_id'           => $orderId,
                    'status_code'        => '404',
                    'transaction_status' => 'not_found',
                    'fraud_status'       => null,
                    'gross_amount'       => null,
                    'currency'           => null,
                ];
            }

            if ($response->failed()) {
                Log::warning('Midtrans Status API returned error', [
                    'order_id'    => $orderId,
                    'status_code' => $response->status(),
                ]);

                return [
                    'transaction_id'     => null,
                    'order_id'           => $orderId,
                    'status_code'        => (string) $response->status(),
                    'transaction_status' => 'unknown',
                    'fraud_status'       => null,
                    'gross_amount'       => null,
                    'currency'           => null,
                ];
            }

            $data = $response->json();

            return [
                'transaction_id'     => $data['transaction_id'] ?? null,
                'order_id'           => $data['order_id'] ?? null,
                'status_code'        => isset($data['status_code']) ? (string) $data['status_code'] : null,
                'transaction_status' => $data['transaction_status'] ?? null,
                'fraud_status'       => $data['fraud_status'] ?? null,
                'gross_amount'       => $data['gross_amount'] ?? null,
                'currency'           => $data['currency'] ?? null,
                'saved_token_id'     => $data['saved_token_id'] ?? null,
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
     * Validasi response provider untuk status gagal yang definitif.
     */
    public function validateProviderFailure(array $remoteStatus, Donation $donation): bool
    {
        if (empty($remoteStatus['order_id']) || $remoteStatus['order_id'] !== $donation->order_id) {
            return false;
        }

        if (empty($remoteStatus['gross_amount']) ||
            !$this->isExactAmountMatch($remoteStatus['gross_amount'], (int) $donation->nominal)) {
            return false;
        }

        $tx = $remoteStatus['transaction_status'] ?? '';
        if (!in_array($tx, ['expire', 'deny', 'cancel'], true) ||
            empty($remoteStatus['status_code']) ||
            !$this->isStatusCodeConsistent((string) $remoteStatus['status_code'], $tx)) {
            return false;
        }

        return true;
    }

    /**
     * Validasi bahwa provider benar-benar melaporkan transaksi pending dan identitasnya cocok.
     */
    public function validateProviderPending(array $remoteStatus, Donation $donation): bool
    {
        if (($remoteStatus['transaction_status'] ?? null) !== 'pending') {
            return false;
        }
        if (empty($remoteStatus['order_id']) || $remoteStatus['order_id'] !== $donation->order_id) {
            return false;
        }
        if (empty($remoteStatus['gross_amount']) ||
            !$this->isExactAmountMatch($remoteStatus['gross_amount'], (int) $donation->nominal)) {
            return false;
        }

        return !empty($remoteStatus['status_code']) &&
            $this->isStatusCodeConsistent((string) $remoteStatus['status_code'], 'pending');
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
            return ['reconciled' => false, 'status' => $donation->status, 'detail' => 'order_not_found_on_provider'];
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

        if (in_array($transactionStatus, ['expire', 'deny', 'cancel'], true)) {
            if (!$this->validateProviderFailure($midtransStatus, $donation)) {
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
