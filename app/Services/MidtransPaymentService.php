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
class MidtransSubscriptionResponseException extends MidtransApiException
{
    protected ?string $providerSubscriptionId;

    public function __construct(
        string $message,
        int $statusCode = 422,
        ?string $providerSubscriptionId = null,
        ?\Throwable $previous = null
    ) {
        $this->providerSubscriptionId = $providerSubscriptionId;
        parent::__construct($message, $statusCode, $previous);
    }

    public function getProviderSubscriptionId(): ?string
    {
        return $this->providerSubscriptionId;
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
    public const MAX_WHOLE_IDR_AMOUNT = '9999999999999';
    public const MAX_WHOLE_IDR_DIGITS = 13;

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

        $grossAmount = $this->parseStrictWholeIdrInt($donation->nominal);
        if ($grossAmount === null) {
            throw new MidtransApiException('Nominal donasi lokal tidak valid untuk transaksi pembayaran.', 422);
        }

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
            $response = $this->httpObservability()->observe(
                'snap_create_transaction',
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->timeout(15)
                    ->post("{$this->apiUrl}/transactions", $payload),
                localDonationId: $donation->id ? (int) $donation->id : null,
                correlationRef: $donation->order_id
            );

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
        mixed $nominal,
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

        $normalizedAmount = $this->normalizeStrictWholeIdrAmount($nominal);
        if ($normalizedAmount === null) {
            throw new MidtransApiException('Nominal donasi rutin tidak valid.', 422);
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
        $startTime = $options['start_time'] ?? $this->scheduleService()->formatOutboundStartTime(now()->utc());

        $payload = [
            'name' => $name,
            'amount' => $normalizedAmount,
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

        $obsLocalDonationId = isset($options['local_donation_id']) && is_numeric($options['local_donation_id'])
            ? (int) $options['local_donation_id']
            : null;
        $obsCorrelationRef = isset($options['correlation_ref']) && is_string($options['correlation_ref'])
            ? $options['correlation_ref']
            : null;

        try {
            $response = $this->httpObservability()->observe(
                'subscription_create',
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders($headers)
                    ->timeout(15)
                    ->post($this->subscriptionApiUrl, $payload),
                localDonationId: $obsLocalDonationId,
                correlationRef: $obsCorrelationRef
            );

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
            if (!is_array($data) || !is_string($data['id'] ?? null) || trim($data['id']) === '') {
                throw new MidtransApiException('ID langganan tidak diterima dari gateway.', 500);
            }

            $createdSubId = trim($data['id']);
            $returnedAmount = array_key_exists('amount', $data) ? $data['amount'] : null;
            $returnedCurrency = $data['currency'] ?? null;
            $currencyInvalid = $returnedCurrency !== null
                && (!is_string($returnedCurrency) || strtoupper(trim($returnedCurrency)) !== 'IDR');

            if (!$this->isExactAmountMatch($returnedAmount, $normalizedAmount) || $currencyInvalid) {
                Log::error('Midtrans Subscription API create response amount/currency unverified', [
                    'campaign_id'     => $campaign->id,
                    'subscription_id' => $createdSubId,
                    'expected_amount' => $normalizedAmount,
                    'returned_amount' => is_scalar($returnedAmount) ? $returnedAmount : gettype($returnedAmount),
                ]);

                throw new MidtransSubscriptionResponseException(
                    'Respons pembuatan langganan dari penyedia tidak memuat nominal yang terverifikasi.',
                    422,
                    $createdSubId
                );
            }

            return $data;
        } catch (MidtransSubscriptionResponseException $e) {
            throw $e;
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

        $grossAmount = $this->parseStrictWholeIdrInt($donation->nominal);
        if ($grossAmount === null) {
            throw new MidtransApiException('Nominal pembayaran awal tidak valid.', 422);
        }

        $chargeUrl = ($this->isProduction ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2') . '/charge';

        $payload = [
            'payment_type' => 'credit_card',
            'transaction_details' => [
                'order_id'     => $donation->order_id,
                'gross_amount' => $grossAmount,
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
            $response = $this->httpObservability()->observe(
                'card_initial_charge',
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders([
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->timeout(15)
                    ->post($chargeUrl, $payload),
                localDonationId: $donation->id ? (int) $donation->id : null,
                correlationRef: $donation->order_id
            );

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
        ?string $savedTokenId = null,
        mixed $verifiedPaymentAmount = null
    ): array {
        if (empty($donation->frequency) || !in_array($donation->frequency, ['mingguan', 'bulanan'], true)) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Bukan transaksi donasi rutin.',
            ];
        }

        $validatedLocalNominal = $this->normalizeStrictWholeIdrAmount($donation->nominal);
        if ($validatedLocalNominal === null) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Nominal donasi lokal tidak valid untuk aktivasi langganan.',
            ];
        }

        $verifiedPaymentSnapshot = null;
        if ($verifiedPaymentAmount !== null) {
            $verifiedPaymentSnapshot = $this->normalizeStrictWholeIdrAmount($verifiedPaymentAmount);
            if ($verifiedPaymentSnapshot === null || $verifiedPaymentSnapshot !== $validatedLocalNominal) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Nominal pembayaran awal tidak konsisten dengan data tagihan.',
                ];
            }
        }

        // 0. TZ0.2 + E5.2 4.3: Blokir sejak awal jika Donation memiliki penanda konflik atau outcome ambigu/manual-review.
        $currentDonationDetail = Donation::whereKey($donation->id)->value('payment_status_detail')
            ?? $donation->payment_status_detail;
        if (in_array($currentDonationDetail, [
            'subscription_created_conflict_manual_review',
            'subscription_created_protected_conflict',
            'subscription_activation_ambiguous_manual_review',
        ], true)) {
            $isAmbiguous = $currentDonationDetail === 'subscription_activation_ambiguous_manual_review';

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => $isAmbiguous,
                'message'      => $isAmbiguous
                    ? 'Status pembuatan langganan dari penyedia masih ambigu (misalnya timeout/server error). Pengiriman ulang otomatis diblokir untuk mencegah langganan ganda; silakan periksa riwayat atau hubungi pengelola.'
                    : 'Aktivasi langganan memerlukan pemeriksaan manual karena konflik data penyedia; pembuatan ulang otomatis diblokir.',
            ];
        }

        // 1. Cek idempotency awal jika subscription_id sudah ada pada donasi
        if (!empty($donation->subscription_id)) {
            $existingSub = Subscription::where('subscription_id', $donation->subscription_id)->first();
            if ($existingSub && !$this->isExistingSubscriptionCompatibleWithDonation($existingSub, $donation, $validatedLocalNominal)) {
                Log::warning('Midtrans recovery blocked due to local subscription identity or parameter mismatch', [
                    'subscription_id' => $existingSub->subscription_id,
                    'order_id'        => $donation->order_id,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => false,
                    'message'      => 'Subscription lokal tidak sesuai dengan pemilik atau parameter donasi.',
                ];
            }
            if ($existingSub && $this->isSubscriptionProtectedFromRecovery($existingSub)) {
                Log::warning('Midtrans recovery blocked for protected local subscription state', [
                    'subscription_id' => $existingSub->subscription_id,
                    'management_state' => $existingSub->management_state,
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => false,
                    'message'      => 'Langganan lokal sudah dikelola atau sedang dalam operasi manajemen; pemulihan dibatalkan untuk melindungi status langganan.',
                ];
            }

            // Hanya jalur pemulihan provider untuk subscription_id yang sudah ada.
            // Kegagalan Get Subscription WAJIB fail-closed; tidak boleh jatuh ke Create API.
            try {
                $remoteSub = $this->getSubscription(
                    $donation->subscription_id,
                    null,
                    $donation->id ? (int) $donation->id : null,
                    $donation->order_id
                );
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
                $amountMatches = $this->isExactAmountMatch($remoteAmount, $donation->nominal);
                $currencyMatches = is_string($remoteCurrency)
                    && strtoupper(trim($remoteCurrency)) === 'IDR';
                $scheduleMatches = (($remoteInterval === 1) || ($remoteInterval === '1'))
                    && is_string($remoteIntervalUnit)
                    && $remoteIntervalUnit === $expectedIntervalUnit;
                $statusMatches = is_string($remoteStatus)
                    && in_array(strtolower(trim($remoteStatus)), ['active', 'pending'], true);

                if ($identityMatches && $amountMatches && $currencyMatches && $scheduleMatches && $statusMatches) {
                    $scheduleService = $this->scheduleService();
                    $intervalUnit  = ($donation->frequency === 'mingguan') ? 'week' : 'month';
                    $normalizedRemoteStatus = strtolower(trim($remoteStatus));

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

                    $parsedRecoverySchedule = $scheduleService->parseMidtransSubscriptionScheduleTimestamp(
                        is_array($remoteSchedule) ? ($remoteSchedule['next_execution_at'] ?? null) : null,
                        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
                    );
                    $metadata['schedule'] = $scheduleService->buildScheduleMetadata($parsedRecoverySchedule);

                    $attributes = [
                        'nominal'           => $validatedLocalNominal,
                        'frequency'         => $donation->frequency,
                        'interval'          => (int) $remoteInterval,
                        'interval_unit'     => $intervalUnit,
                        'payment_type'      => strtolower((string) ($remoteSub['payment_type'] ?? 'credit_card')),
                        'status'            => $normalizedRemoteStatus,
                        'is_anonim'         => (bool) $donation->is_anonim,
                        'idempotency_key'   => $remoteSub['metadata']['idempotency_key'] ?? $donation->idempotency_key,
                        'next_execution_at' => $parsedRecoverySchedule['valid'] ? $parsedRecoverySchedule['utc'] : null,
                        'metadata'          => $metadata,
                    ];
                    if ($providerToken !== null) {
                        $attributes['token'] = $providerToken;
                    }

                    $recoveryOutcome = DB::transaction(function () use (
                        $donation, $remoteSub, $remoteAmount, $attributes, $providerToken, $parsedRecoverySchedule
                    ): array {
                        $lockedDonation = Donation::where('id', $donation->id)->lockForUpdate()->first();
                        if (!$lockedDonation) {
                            return ['status' => 'amount_mismatch', 'subscription' => null];
                        }

                        if ($lockedDonation->status !== 'terverifikasi') {
                            return ['status' => 'not_verified', 'subscription' => null];
                        }

                        if (!empty($lockedDonation->subscription_id) && $lockedDonation->subscription_id !== $remoteSub['id']) {
                            return ['status' => 'identity_conflict', 'subscription' => null];
                        }

                        $lockedNominal = $this->normalizeStrictWholeIdrAmount($lockedDonation->nominal);
                        if ($lockedNominal === null || !$this->isExactAmountMatch($remoteAmount, $lockedNominal)) {
                            return ['status' => 'amount_mismatch', 'subscription' => null];
                        }

                        $attributes['nominal'] = $lockedNominal;
                        $sub = Subscription::where('subscription_id', $remoteSub['id'])->lockForUpdate()->first();
                        if ($sub) {
                            if (!$this->isExistingSubscriptionCompatibleWithDonation($sub, $lockedDonation, $lockedNominal)) {
                                return ['status' => 'identity_conflict', 'subscription' => null];
                            }

                            if ($this->isSubscriptionProtectedFromRecovery($sub)) {
                                return ['status' => 'protected_state', 'subscription' => null];
                            }

                            $existingMeta = is_array($sub->metadata) ? $sub->metadata : [];
                            $existingScheduleMeta = is_array($existingMeta['schedule'] ?? null) ? $existingMeta['schedule'] : [];
                            $existingCanonical = ($existingScheduleMeta['next_execution_source'] ?? null) === 'provider_confirmed'
                                ? $this->scheduleService()->parseProviderTimestamp($existingScheduleMeta['next_execution_utc'] ?? null)
                                : ['valid' => false];
                            if (!$parsedRecoverySchedule['valid'] && $existingCanonical['valid'] && $sub->next_execution_at !== null) {
                                $attributes['next_execution_at'] = $sub->next_execution_at;
                                $attributes['metadata']['schedule'] = $this->scheduleService()->markPreservedScheduleStale(
                                    $existingScheduleMeta,
                                    $parsedRecoverySchedule
                                );
                            }

                            $mergedMetadata = array_merge($existingMeta, $attributes['metadata']);
                            $sub->update(array_merge($attributes, ['metadata' => $mergedMetadata]));
                        } else {
                            $sub = Subscription::create(array_merge([
                                'subscription_id' => $remoteSub['id'],
                                'user_id'         => $lockedDonation->user_id,
                                'campaign_id'     => $lockedDonation->campaign_id,
                            ], $attributes));
                        }

                        $lockedDonation->update([
                            'payment_status_detail' => $providerToken === null
                                ? 'subscription_recovered_missing_saved_token'
                                : 'subscription_activated',
                        ]);

                        return ['status' => 'ok', 'subscription' => $sub];
                    });

                    if ($recoveryOutcome['status'] === 'not_verified') {
                        return [
                            'success'      => false,
                            'subscription' => null,
                            'recoverable'  => false,
                            'message'      => 'Pembayaran awal belum berstatus terverifikasi untuk pemulihan langganan.',
                        ];
                    }

                    if ($recoveryOutcome['status'] === 'protected_state' || $recoveryOutcome['status'] === 'identity_conflict') {
                        Log::warning('Midtrans subscription recovery aborted in locked check', [
                            'subscription_id' => $donation->subscription_id,
                            'reason' => $recoveryOutcome['status'],
                        ]);
                        return [
                            'success'      => false,
                            'subscription' => null,
                            'recoverable'  => false,
                            'message'      => $recoveryOutcome['status'] === 'protected_state'
                                ? 'Langganan lokal sudah dikelola atau sedang dalam operasi manajemen; pemulihan dibatalkan untuk melindungi status langganan.'
                                : 'Subscription lokal tidak sesuai dengan pemilik atau parameter donasi.',
                        ];
                    }

                    $recoveredSub = $recoveryOutcome['subscription'];
                    if ($recoveredSub === null) {
                        return [
                            'success'      => false,
                            'subscription' => null,
                            'recoverable'  => true,
                            'message'      => 'Nominal donasi lokal tidak konsisten saat pemulihan langganan.',
                        ];
                    }

                    return [
                        'success'      => true,
                        'subscription' => $recoveredSub,
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
        // Kontrak Section 8.1: kepemilikan savedTokenId + nominal lokal saja tidak diperlakukan sebagai bukti pembayaran;
        // bila bukti nominal terverifikasi eksplisit belum tersedia, wajib memeriksa Status API otoritatif.
        $verifiedToken = $savedTokenId;
        if ($donation->status !== 'terverifikasi' || empty($verifiedToken) || $verifiedPaymentSnapshot === null) {
            try {
                $remoteStatus = $this->checkTransactionStatus($donation->order_id, $donation->id ? (int) $donation->id : null);
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

            // B. Jika transaksi ditolak / gagal: wajib bukti order, mata uang, & nominal terverifikasi sebelum mutasi (C3, D0.1)
            if (in_array($remoteTxStatus, ['deny', 'cancel', 'expire'], true) || $remoteFraud === 'deny') {
                $isVerifiedFailure = $this->validateProviderFailure($remoteStatus, $donation)
                    || (
                        $remoteTxStatus === 'capture'
                        && $remoteFraud === 'deny'
                        && !empty($remoteOrderId)
                        && $remoteOrderId === $donation->order_id
                        && $this->isExactAmountMatch($remoteAmount, $donation->nominal)
                        && $this->isOptionalIdrCurrencyValid($remoteStatus)
                        && !empty($remoteCode)
                        && $this->isStatusCodeConsistent($remoteCode, 'capture')
                    );

                if (!$isVerifiedFailure) {
                    return [
                        'success'      => false,
                        'subscription' => null,
                        'message'      => 'Respons penolakan pembayaran awal tidak lengkap atau nominal tidak sesuai.',
                    ];
                }

                $markedFailed = DB::transaction(function () use ($donation, $remoteAmount, $remoteTxStatus, $remoteFraud): bool {
                    $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                    if (!$locked || $locked->status === 'terverifikasi') {
                        return false;
                    }
                    if (!$this->isExactAmountMatch($remoteAmount, $locked->nominal)) {
                        return false;
                    }
                    $locked->update([
                        'status'                     => 'gagal',
                        'payment_status_detail'      => 'charge_denied',
                        'webhook_processed_at'       => now(),
                        'webhook_transaction_status' => is_string($remoteTxStatus) ? $remoteTxStatus : null,
                        'webhook_fraud_status'       => is_string($remoteFraud) ? $remoteFraud : null,
                    ]);
                    return true;
                });

                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => $markedFailed
                        ? 'Otorisasi pembayaran awal ditolak oleh penyedia.'
                        : 'Status penolakan pembayaran awal tidak diterapkan karena status atau nominal lokal tidak sesuai.',
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
            if (!$this->isExactAmountMatch($remoteAmount, $donation->nominal)) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Nominal pembayaran awal tidak sesuai dengan data tagihan.',
                ];
            }

            $verifiedPaymentSnapshot = $this->normalizeStrictWholeIdrAmount($remoteAmount);
            if ($verifiedPaymentSnapshot === null) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Nominal pembayaran awal tidak valid untuk aktivasi langganan.',
                ];
            }

            // E. Validasi mata uang
            if (!$this->isOptionalIdrCurrencyValid($remoteStatus)) {
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

            // Singkat & atomik: re-validasi nominal pada locked record, lalu tandai donasi awal terverifikasi jika belum
            $lockedAmountValid = DB::transaction(function () use ($donation, $remoteTxStatus, $remoteFraud, $remoteAmount) {
                $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                if (!$locked || !$this->isExactAmountMatch($remoteAmount, $locked->nominal)) {
                    return false;
                }
                if ($locked->status !== 'terverifikasi') {
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
                return true;
            });

            if (!$lockedAmountValid) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'message'      => 'Nominal pembayaran awal tidak sesuai dengan data tagihan.',
                ];
            }
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

        if ($verifiedPaymentSnapshot === null) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Bukti nominal pembayaran terverifikasi dari penyedia tidak tersedia.',
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

        $scheduleService = $this->scheduleService();
        $intervalUnit  = ($donation->frequency === 'mingguan') ? 'week' : 'month';
        $nextCycleTime = $scheduleService->calculateNextStartTime(now()->utc(), $donation->frequency);
        $startTimeStr  = $scheduleService->formatOutboundStartTime($nextCycleTime);

        $subIdempotencyKey = $donation->idempotency_key
            ? str_replace('init_', '', $donation->idempotency_key)
            : substr(hash('sha256', 'wecare-subscription|' . $donation->order_id), 0, 36);

        // 3. Cek jika Subscription dengan idempotency_key ini sudah dibuat (F1: konsistensi identitas & state terproteksi)
        $existingSub = Subscription::where('idempotency_key', $subIdempotencyKey)->first();
        if ($existingSub) {
            $step3Outcome = DB::transaction(function () use ($donation, $existingSub, $verifiedPaymentSnapshot): array {
                $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                $lockedSub = Subscription::whereKey($existingSub->id)->lockForUpdate()->first();
                if (
                    !$locked
                    || !$lockedSub
                    || $locked->status !== 'terverifikasi'
                    || !$this->isExactAmountMatch($verifiedPaymentSnapshot, $locked->nominal)
                    || !$this->isExistingSubscriptionCompatibleWithDonation($lockedSub, $locked, $verifiedPaymentSnapshot)
                ) {
                    return ['status' => 'mismatch', 'subscription' => null];
                }

                if (!$this->isSubscriptionActiveAndUnprotected($lockedSub)) {
                    return ['status' => 'protected_or_inactive', 'subscription' => $lockedSub];
                }

                $locked->update([
                    'subscription_id'       => $lockedSub->subscription_id,
                    'payment_status_detail' => 'subscription_activated',
                ]);

                return ['status' => 'ok', 'subscription' => $lockedSub];
            });

            if ($step3Outcome['status'] === 'protected_or_inactive') {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => false,
                    'message'      => 'Langganan yang sudah ada tidak aktif atau sedang dikelola; aktivasi ulang diblokir.',
                ];
            }

            if ($step3Outcome['status'] !== 'ok' || !$step3Outcome['subscription']) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => false,
                    'message'      => 'Data langganan yang sudah ada tidak konsisten dengan nominal atau parameter donasi.',
                ];
            }

            return [
                'success'      => true,
                'subscription' => $step3Outcome['subscription'],
                'message'      => 'Langganan sudah aktif (idempotent).',
            ];
        }

        // 4. State guard atomik untuk koordinasi concurrent request + verifikasi terhadap snapshot bukti bayar otoritatif (C4, D0.2, F1, F4)
        $guardResult = DB::transaction(function () use ($donation, $verifiedPaymentSnapshot): array {
            $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
            if (!$locked) {
                return ['state' => 'not_found', 'nominal' => null, 'subscription_id' => null];
            }

            if ($locked->status !== 'terverifikasi') {
                return ['state' => 'not_verified', 'nominal' => null, 'subscription_id' => null];
            }

            $lockedNominal = $this->normalizeStrictWholeIdrAmount($locked->nominal);
            if ($lockedNominal === null) {
                return ['state' => 'invalid_nominal', 'nominal' => null, 'subscription_id' => null];
            }

            if ($verifiedPaymentSnapshot === null || $lockedNominal !== $verifiedPaymentSnapshot) {
                return ['state' => 'amount_mismatch', 'nominal' => null, 'subscription_id' => null];
            }

            if (in_array($locked->payment_status_detail, [
                'subscription_created_conflict_manual_review',
                'subscription_created_protected_conflict',
                'subscription_activation_ambiguous_manual_review',
            ], true)) {
                return ['state' => $locked->payment_status_detail === 'subscription_activation_ambiguous_manual_review' ? 'ambiguous_manual_review' : 'manual_review_required', 'nominal' => $lockedNominal, 'subscription_id' => $locked->subscription_id];
            }

            if (!empty($locked->subscription_id)) {
                return ['state' => 'already_active', 'nominal' => $lockedNominal, 'subscription_id' => $locked->subscription_id];
            }

            if ($locked->payment_status_detail === 'activation_in_progress') {
                $leaseStartedAt = $locked->updated_at;
                if ($leaseStartedAt && $leaseStartedAt->lte(now()->subMinutes(5))) {
                    // Lease kadaluarsa tanpa subscription_id: jangan kirim POST baru secara otomatis (E5.2 4.3).
                    $locked->update(['payment_status_detail' => 'subscription_activation_ambiguous_manual_review']);
                    return ['state' => 'ambiguous_manual_review', 'nominal' => $lockedNominal, 'subscription_id' => null];
                }
                return ['state' => 'in_progress', 'nominal' => $lockedNominal, 'subscription_id' => null];
            }

            $locked->update(['payment_status_detail' => 'activation_in_progress']);
            return ['state' => 'acquired', 'nominal' => $lockedNominal, 'subscription_id' => null];
        });

        $stateAcquired = $guardResult['state'];
        $operationNominal = $guardResult['nominal'];

        if ($stateAcquired === 'not_found') {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Donasi awal tidak ditemukan.',
            ];
        }

        if ($stateAcquired === 'not_verified' || $stateAcquired === 'amount_mismatch') {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Nominal atau status donasi tidak konsisten dengan bukti pembayaran terverifikasi.',
            ];
        }

        if ($stateAcquired === 'invalid_nominal' || $operationNominal === null) {
            return [
                'success'      => false,
                'subscription' => null,
                'message'      => 'Nominal donasi lokal tidak valid untuk aktivasi langganan.',
            ];
        }

        if ($stateAcquired === 'ambiguous_manual_review') {
            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => true,
                'message'      => 'Status pembuatan langganan dari penyedia masih ambigu. Pembuatan ulang otomatis diblokir untuk mencegah langganan ganda.',
            ];
        }

        if ($stateAcquired === 'manual_review_required') {
            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'message'      => 'Aktivasi langganan memerlukan pemeriksaan manual karena konflik data penyedia; pembuatan ulang otomatis diblokir.',
            ];
        }

        if ($stateAcquired === 'already_active') {
            $alreadyOutcome = DB::transaction(function () use ($donation, $operationNominal): array {
                $lockedDonation = Donation::where('id', $donation->id)->lockForUpdate()->first();
                if (!$lockedDonation || empty($lockedDonation->subscription_id)) {
                    return ['status' => 'missing_sub', 'subscription' => null];
                }

                $existingSub = Subscription::where('subscription_id', $lockedDonation->subscription_id)->lockForUpdate()->first();
                if (!$existingSub) {
                    return ['status' => 'missing_sub', 'subscription' => null];
                }

                if (!$this->isExistingSubscriptionCompatibleWithDonation($existingSub, $lockedDonation, $operationNominal)) {
                    return ['status' => 'mismatch', 'subscription' => null];
                }

                if (!$this->isSubscriptionActiveAndUnprotected($existingSub)) {
                    return ['status' => 'protected_or_inactive', 'subscription' => $existingSub];
                }

                return ['status' => 'ok', 'subscription' => $existingSub];
            });

            if ($alreadyOutcome['status'] === 'ok' && $alreadyOutcome['subscription']) {
                return [
                    'success'      => true,
                    'subscription' => $alreadyOutcome['subscription'],
                    'message'      => 'Langganan sudah aktif (idempotent).',
                ];
            }

            if ($alreadyOutcome['status'] === 'missing_sub') {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'message'      => 'Langganan belum tersinkronisasi lokal; silakan lakukan pemulihan.',
                ];
            }

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'message'      => $alreadyOutcome['status'] === 'protected_or_inactive'
                    ? 'Langganan yang sudah ada tidak aktif atau sedang dikelola; aktivasi ulang diblokir.'
                    : 'Data langganan yang sudah ada tidak konsisten dengan pemilik atau parameter donasi.',
            ];
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
                $operationNominal,
                $donation->frequency,
                'credit_card',
                $verifiedToken,
                (bool) $donation->is_anonim,
                $subIdempotencyKey,
                [
                    'start_time' => $startTimeStr,
                    'local_donation_id' => $donation->id ? (int) $donation->id : null,
                    'correlation_ref' => $donation->order_id,
                ]
            );
        } catch (MidtransSubscriptionResponseException $e) {
            // Provider mengembalikan ID tetapi respons nominal tidak terverifikasi (TZ0.2: simpan bukti tanpa menimpa/mengaitkan konflik)
            $createdSubId = $e->getProviderSubscriptionId();
            if ($createdSubId) {
                $evidenceOutcome = $this->recordCreatedSubscriptionExceptionEvidence(
                    $donation,
                    $createdSubId,
                    $operationNominal,
                    'subscription_response_amount_unverified_recoverable'
                );

                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => $evidenceOutcome !== 'manual_review',
                    'message'      => $evidenceOutcome === 'manual_review'
                        ? 'Langganan dibuat dengan konflik data lokal; ditandai untuk pemeriksaan manual tanpa pembuatan ulang.'
                        : 'Respons pembuatan langganan dari penyedia tidak memuat nominal yang terverifikasi. Status disimpan untuk pemeriksaan ulang tanpa pembuatan ulang.',
                ];
            }

            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'subscription_activation_ambiguous_manual_review',
                ]);
            });

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => true,
                'message'      => 'Respons pembuatan langganan dari penyedia tidak memuat ID yang pasti. Pembayaran awal aman; pembuatan ulang otomatis diblokir.',
            ];
        } catch (MidtransConnectionException $e) {
            // E5.2 4.3: Timeout/koneksi terputus tanpa ID provider berisiko POST sudah diproses di Midtrans -> blokir retry POST otomatis.
            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'subscription_activation_ambiguous_manual_review',
                ]);
            });

            Log::error('Timeout saat createSubscription ke Midtrans (ambiguous manual review)', [
                'order_id' => $donation->order_id,
            ]);

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => true,
                'message'      => 'Koneksi ke gateway langganan mengalami timeout dan status pembuatan langganan belum pasti. Pembayaran awal Anda aman dan pengiriman ulang otomatis diblokir untuk mencegah langganan ganda.',
            ];
        } catch (MidtransApiException $e) {
            $statusCode = (int) $e->getCode();
            $isDefinitiveClientRejection = in_array($statusCode, [400, 401, 403, 404, 422], true);

            DB::transaction(function () use ($donation, $isDefinitiveClientRejection) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => $isDefinitiveClientRejection
                        ? 'subscription_activation_failed_recoverable'
                        : 'subscription_activation_ambiguous_manual_review',
                ]);
            });

            Log::error('Error API saat createSubscription setelah initial charge berhasil', [
                'order_id'    => $donation->order_id,
                'donation_id' => $donation->id,
                'status_code' => $statusCode,
                'definitive_rejection' => $isDefinitiveClientRejection,
            ]);

            if ($isDefinitiveClientRejection) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'ambiguous'    => false,
                    'message'      => 'Pembayaran awal berhasil, namun permintaan langganan ditolak sebelum dibuat. Status dapat diverifikasi ulang tanpa penagihan ulang.',
                ];
            }

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => true,
                'message'      => 'Gateway langganan mengembalikan respons yang belum pasti. Pembayaran awal aman dan pembuatan ulang otomatis diblokir.',
            ];
        } catch (\Throwable $e) {
            DB::transaction(function () use ($donation) {
                Donation::where('id', $donation->id)->update([
                    'payment_status_detail' => 'subscription_activation_ambiguous_manual_review',
                ]);
            });

            Log::error('Gagal tak terduga saat createSubscription setelah initial charge berhasil (ambiguous)', [
                'order_id'    => $donation->order_id,
                'donation_id' => $donation->id,
                'error'       => $e->getMessage(),
            ]);

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => false,
                'ambiguous'    => true,
                'message'      => 'Pembayaran awal berhasil, namun status pembuatan jadwal langganan belum pasti. Pembuatan ulang otomatis diblokir untuk mencegah langganan ganda.',
            ];
        }

        if (!$this->isExactAmountMatch($subResult['amount'] ?? null, $operationNominal)) {
            $createdSubId = is_string($subResult['id'] ?? null) ? trim($subResult['id']) : null;
            $evidenceOutcome = $this->recordCreatedSubscriptionExceptionEvidence(
                $donation,
                $createdSubId,
                $operationNominal,
                'subscription_response_amount_unverified_recoverable'
            );

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => $evidenceOutcome !== 'manual_review',
                'message'      => $evidenceOutcome === 'manual_review'
                    ? 'Langganan dibuat dengan konflik data lokal; ditandai untuk pemeriksaan manual tanpa pembuatan ulang.'
                    : 'Respons pembuatan langganan dari penyedia tidak memuat nominal yang terverifikasi. Status disimpan untuk pemeriksaan ulang tanpa pembuatan ulang.',
            ];
        }

        // 6. Simpan record Subscription lokal secara atomik dalam transaksi database singkat (C4, F4, TZ0.2)
        try {
            $persistenceOutcome = DB::transaction(function () use (
                $subResult, $donation, $user, $campaign, $verifiedToken,
                $intervalUnit, $nextCycleTime, $subIdempotencyKey, $operationNominal
            ): array {
                $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'terverifikasi' || !$this->isExactAmountMatch($operationNominal, $locked->nominal)) {
                    if ($locked) {
                        $createdSubId = is_string($subResult['id'] ?? null) ? trim($subResult['id']) : null;
                        $existingConflictSub = $createdSubId
                            ? Subscription::where('subscription_id', $createdSubId)->lockForUpdate()->first()
                            : null;
                        $hasDonationConflict = !empty($createdSubId) && !empty($locked->subscription_id) && $locked->subscription_id !== $createdSubId;
                        $hasSubConflict = $existingConflictSub !== null && (
                            !$this->isExistingSubscriptionCompatibleWithDonation($existingConflictSub, $locked, $operationNominal)
                            || $this->isSubscriptionProtectedFromRecovery($existingConflictSub)
                        );
                        if ($hasDonationConflict || $hasSubConflict) {
                            $locked->update(['payment_status_detail' => 'subscription_created_conflict_manual_review']);
                            return ['status' => 'identity_conflict', 'subscription' => null];
                        }
                        $updates = ['payment_status_detail' => 'subscription_local_amount_mismatch_recoverable'];
                        if ($createdSubId && empty($locked->subscription_id)) {
                            $updates['subscription_id'] = $createdSubId;
                        }
                        $locked->update($updates);
                    }
                    return ['status' => 'amount_mismatch', 'subscription' => null];
                }

                if (!empty($locked->subscription_id) && $locked->subscription_id !== $subResult['id']) {
                    $locked->update([
                        'payment_status_detail' => 'subscription_created_conflict_manual_review',
                    ]);
                    return ['status' => 'existing_donation_subscription_conflict', 'subscription' => null];
                }

                $sub = Subscription::where('subscription_id', $subResult['id'])->lockForUpdate()->first();
                if ($sub) {
                    if (
                        (int) $sub->user_id !== (int) $locked->user_id
                        || (int) $sub->campaign_id !== (int) $locked->campaign_id
                        || $sub->frequency !== $locked->frequency
                    ) {
                        $locked->update([
                            'payment_status_detail' => 'subscription_created_conflict_manual_review',
                        ]);
                        return ['status' => 'identity_conflict', 'subscription' => null];
                    }

                    if ($this->isSubscriptionProtectedFromRecovery($sub)) {
                        $updates = ['payment_status_detail' => 'subscription_created_protected_conflict'];
                        if (empty($locked->subscription_id)) {
                            $updates['subscription_id'] = $sub->subscription_id;
                        }
                        $locked->update($updates);
                        return ['status' => 'protected_state', 'subscription' => null];
                    }

                    if (!$this->isExactAmountMatch($operationNominal, $sub->nominal)) {
                        $updates = ['payment_status_detail' => 'subscription_local_amount_mismatch_recoverable'];
                        if (empty($locked->subscription_id)) {
                            $updates['subscription_id'] = $sub->subscription_id;
                        }
                        $locked->update($updates);
                        return ['status' => 'amount_mismatch', 'subscription' => null];
                    }
                }

                if (!$sub) {
                    $scheduleService = $this->scheduleService();
                    $remoteCreateSchedule = is_array($subResult['schedule'] ?? null) ? $subResult['schedule'] : null;
                    $rawCreateNextExec = $remoteCreateSchedule !== null ? ($remoteCreateSchedule['next_execution_at'] ?? null) : null;

                    if ($this->isCreateSubscriptionScheduleContextTrusted($subResult, $intervalUnit, $operationNominal)) {
                        $parsedCreateSchedule = $scheduleService->parseMidtransSubscriptionScheduleTimestamp(
                            $rawCreateNextExec,
                            SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE
                        );
                    } else {
                        $parsedCreateSchedule = [
                            'valid' => false,
                            'utc' => null,
                            'status' => $rawCreateNextExec === null ? 'unavailable' : 'invalid',
                            'reason' => $rawCreateNextExec === null ? 'missing_timestamp' : 'unverified_create_schedule_context',
                        ];
                    }

                    $createMetadata = is_array($subResult['metadata'] ?? null) ? $subResult['metadata'] : [];
                    $createMetadata['schedule'] = $scheduleService->buildScheduleMetadata($parsedCreateSchedule, $nextCycleTime);

                    $sub = Subscription::create([
                        'subscription_id'   => $subResult['id'],
                        'user_id'           => $user->id,
                        'campaign_id'       => $campaign->id,
                        'nominal'           => $operationNominal,
                        'frequency'         => $donation->frequency,
                        'interval'          => 1,
                        'interval_unit'     => $intervalUnit,
                        'payment_type'      => 'credit_card',
                        'token'             => $verifiedToken,
                        'status'            => $subResult['status'] ?? 'active',
                        'is_anonim'         => (bool) $donation->is_anonim,
                        'idempotency_key'   => $subIdempotencyKey,
                        'next_execution_at' => $parsedCreateSchedule['valid'] ? $parsedCreateSchedule['utc'] : null,
                        'metadata'          => $createMetadata,
                    ]);
                }

                $locked->update([
                    'subscription_id'       => $sub->subscription_id,
                    'payment_status_detail' => 'subscription_activated',
                ]);

                return ['status' => 'ok', 'subscription' => $sub];
            });

            if (in_array($persistenceOutcome['status'], ['existing_donation_subscription_conflict', 'identity_conflict', 'protected_state'], true)) {
                Log::warning('Midtrans Subscription created on provider but local binding blocked safely (F4)', [
                    'order_id'                 => $donation->order_id,
                    'provider_subscription_id' => $subResult['id'] ?? null,
                    'reason'                   => $persistenceOutcome['status'],
                ]);
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => false,
                    'message'      => 'Langganan lokal sudah dikelola atau tidak sesuai dengan donasi; sinkronisasi dibatalkan untuk melindungi status langganan.',
                ];
            }

            if ($persistenceOutcome['status'] !== 'ok' || !$persistenceOutcome['subscription']) {
                return [
                    'success'      => false,
                    'subscription' => null,
                    'recoverable'  => true,
                    'message'      => 'Langganan dibuat di penyedia tetapi nominal lokal tidak konsisten; sinkronisasi ditunda untuk pemeriksaan ulang.',
                ];
            }

            $subscription = $persistenceOutcome['subscription'];

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
            // Skenario: Provider create success, tapi penyimpanan lokal gagal (F4: pertahankan bukti secara aman tanpa menimpa ID lain/asing)
            Log::error('Gagal menyimpan subscription lokal setelah provider create berhasil', [
                'order_id'        => $donation->order_id,
                'subscription_id' => $subResult['id'] ?? null,
            ]);

            $createdSubId = is_string($subResult['id'] ?? null) ? trim($subResult['id']) : null;
            $evidenceOutcome = $this->recordCreatedSubscriptionExceptionEvidence(
                $donation,
                $createdSubId,
                $operationNominal,
                'subscription_local_save_failed_recoverable'
            );

            return [
                'success'      => false,
                'subscription' => null,
                'recoverable'  => $evidenceOutcome !== 'manual_review',
                'message'      => $evidenceOutcome === 'manual_review'
                    ? 'Langganan dibuat dengan konflik data lokal; ditandai untuk pemeriksaan manual tanpa pembuatan ulang.'
                    : 'Langganan berhasil dibuat di penyedia, sinkronisasi data lokal tertunda. Silakan verifikasi ulang.',
            ];
        }
    }

    /**
     * Mencatat bukti pembuatan subscription di jalur error/exception tanpa menimpa subscription_id berbeda
     * atau mengaitkan Subscription lokal yang konflik/terproteksi (F4, TZ0.2).
     */
    protected function recordCreatedSubscriptionExceptionEvidence(
        Donation $donation,
        ?string $createdSubId,
        string $operationNominal,
        string $defaultDetail
    ): string {
        $normalizedCreatedId = is_string($createdSubId) && trim($createdSubId) !== ''
            ? trim($createdSubId)
            : null;

        try {
            return DB::transaction(function () use ($donation, $normalizedCreatedId, $operationNominal, $defaultDetail): string {
                $locked = Donation::where('id', $donation->id)->lockForUpdate()->first();
                if (!$locked) {
                    return 'recoverable';
                }

                $existingSub = $normalizedCreatedId
                    ? Subscription::where('subscription_id', $normalizedCreatedId)->lockForUpdate()->first()
                    : null;

                $hasDonationSubConflict = $normalizedCreatedId !== null
                    && !empty($locked->subscription_id)
                    && $locked->subscription_id !== $normalizedCreatedId;

                $hasSubConflict = $existingSub !== null && (
                    !$this->isExistingSubscriptionCompatibleWithDonation($existingSub, $locked, $operationNominal)
                    || $this->isSubscriptionProtectedFromRecovery($existingSub)
                );

                if ($hasDonationSubConflict || $hasSubConflict) {
                    $locked->update([
                        'payment_status_detail' => 'subscription_created_conflict_manual_review',
                    ]);
                    return 'manual_review';
                }

                $updates = ['payment_status_detail' => $defaultDetail];
                if ($normalizedCreatedId !== null && empty($locked->subscription_id)) {
                    $updates['subscription_id'] = $normalizedCreatedId;
                }
                $locked->update($updates);
                return 'recoverable';
            });
        } catch (\Throwable $e) {
            return 'recoverable';
        }
    }

    /**
     * Mengambil detail langganan dari Midtrans.
     */
    public function getSubscription(
        string $subscriptionId,
        ?int $localSubscriptionId = null,
        ?int $localDonationId = null,
        ?string $correlationRef = null
    ): array {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi.');
        }

        try {
            $response = $this->httpObservability()->observe(
                'subscription_get',
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders(['Accept' => 'application/json'])
                    ->timeout(10)
                    ->get("{$this->subscriptionApiUrl}/{$subscriptionId}"),
                localSubscriptionId: $localSubscriptionId,
                localDonationId: $localDonationId,
                correlationRef: $correlationRef
            );

            if ($response->failed()) {
                throw new MidtransApiException('Gagal mengambil data langganan dari Midtrans.', $response->status());
            }

            return $response->json();
        } catch (ConnectionException $e) {
            throw new MidtransConnectionException('Koneksi ke gateway Midtrans terputus.');
        }
    }

    public function disableSubscription(string $subscriptionId, ?int $localSubscriptionId = null): array
    {
        return $this->mutateSubscription($subscriptionId, 'disable', $localSubscriptionId);
    }

    public function enableSubscription(string $subscriptionId, ?int $localSubscriptionId = null): array
    {
        return $this->mutateSubscription($subscriptionId, 'enable', $localSubscriptionId);
    }

    public function cancelSubscription(string $subscriptionId, ?int $localSubscriptionId = null): array
    {
        return $this->mutateSubscription($subscriptionId, 'cancel', $localSubscriptionId);
    }

    protected function mutateSubscription(string $subscriptionId, string $operation, ?int $localSubscriptionId = null): array
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
            $response = $this->httpObservability()->observe(
                'subscription_' . $operation,
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders(['Accept' => 'application/json'])
                    ->timeout(15)
                    ->post("{$this->subscriptionApiUrl}/{$subscriptionId}/{$operation}"),
                localSubscriptionId: $localSubscriptionId
            );

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
    public function checkTransactionStatus(string $orderId, ?int $localDonationId = null): array
    {
        if (!$this->isConfigured()) {
            throw new MidtransConfigurationException('Midtrans Server Key belum dikonfigurasi.');
        }

        try {
            $response = $this->httpObservability()->observe(
                'transaction_get_status',
                fn () => Http::withBasicAuth($this->serverKey, '')
                    ->withHeaders([
                        'Accept' => 'application/json',
                    ])
                    ->timeout(10)
                    ->get("{$this->statusApiUrl}/{$orderId}/status"),
                localDonationId: $localDonationId,
                correlationRef: $orderId
            );

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

    protected function httpObservability(): MidtransHttpObservability
    {
        return app(MidtransHttpObservability::class);
    }

    protected function scheduleService(): SubscriptionScheduleService
    {
        return app(SubscriptionScheduleService::class);
    }

    /**
     * Memvalidasi kesesuaian konteks respons Create Subscription (nominal, mata uang, status, interval)
     * sebelum mempercayai timestamp jadwal yang dikembalikan penyedia.
     */
    protected function isCreateSubscriptionScheduleContextTrusted(
        array $subResult,
        string $expectedIntervalUnit,
        mixed $expectedNominal
    ): bool {
        if (!$this->isExactAmountMatch($subResult['amount'] ?? null, $expectedNominal)) {
            return false;
        }

        if (array_key_exists('currency', $subResult) && $subResult['currency'] !== null) {
            if (!is_string($subResult['currency']) || strtoupper(trim($subResult['currency'])) !== 'IDR') {
                return false;
            }
        }

        $remoteStatus = $subResult['status'] ?? null;
        if (!is_string($remoteStatus) || !in_array(strtolower(trim($remoteStatus)), ['active', 'pending'], true)) {
            return false;
        }

        $remoteSchedule = $subResult['schedule'] ?? null;
        if (!is_array($remoteSchedule)) {
            return false;
        }

        $remoteInterval = $remoteSchedule['interval'] ?? null;
        $remoteIntervalUnit = $remoteSchedule['interval_unit'] ?? null;

        $intervalMatches = ($remoteInterval === 1) || ($remoteInterval === '1');
        $unitMatches = is_string($remoteIntervalUnit)
            && strtolower(trim($remoteIntervalUnit)) === strtolower(trim($expectedIntervalUnit));

        return $intervalMatches && $unitMatches;
    }

    /**
     * Memeriksa kesesuaian identitas, campaign, frekuensi, nominal, dan keterkaitan subscription_id
     * antara Subscription yang sudah ada dan record Donation awal (F1, F4).
     */
    protected function isExistingSubscriptionCompatibleWithDonation(
        Subscription $sub,
        Donation $donation,
        mixed $expectedNominal = null
    ): bool {
        if (
            (int) $sub->user_id !== (int) $donation->user_id
            || (int) $sub->campaign_id !== (int) $donation->campaign_id
            || $sub->frequency !== $donation->frequency
        ) {
            return false;
        }

        if (!$this->isExactAmountMatch($sub->nominal, $expectedNominal ?? $donation->nominal)) {
            return false;
        }

        return empty($donation->subscription_id) || $donation->subscription_id === $sub->subscription_id;
    }

    /**
     * Memeriksa apakah record Subscription lokal benar-benar aktif dan tidak sedang terproteksi/dikelola (F1).
     */
    protected function isSubscriptionActiveAndUnprotected(Subscription $sub): bool
    {
        return !$this->isSubscriptionProtectedFromRecovery($sub)
            && strtolower(trim((string) $sub->status)) === 'active';
    }

    /**
     * Memeriksa apakah record Subscription lokal berada dalam status manajemen/guard yang dilindungi
     * sehingga alur recovery tidak boleh menimpa status, metadata pembatalan, atau lock operasinya (D6, F3).
     *
     * Catatan F3: Keterlambatan/kedaluwarsaan lease tidak mencabut kepemilikan guard secara otomatis;
     * selama operation_guard_owner masih terisi, recovery tidak boleh memutasi ataupun menghapus guard tersebut.
     */
    protected function isSubscriptionProtectedFromRecovery(Subscription $sub): bool
    {
        if (in_array($sub->management_state, ['cancelled', 'paused', 'reconciliation'], true)) {
            return true;
        }

        if (is_array($sub->metadata) && array_key_exists('cancellation', $sub->metadata)) {
            return true;
        }

        return !empty($sub->operation_guard_owner);
    }

    /**
     * Memvalidasi mata uang respons provider bersifat IDR jika key mata uang disertakan dan tidak null.
     * Key yang tidak ada atau bernilai null diizinkan untuk kompatibilitas respons failure Midtrans yang mengomit currency;
     * nilai selain string 'IDR' (case-insensitive) termasuk string kosong, angka, bool, atau array ditolak.
     */
    public function isOptionalIdrCurrencyValid(array $payload, string $key = 'currency'): bool
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return true;
        }

        return is_string($payload[$key]) && strtoupper($payload[$key]) === 'IDR';
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
        // 2. Validasi gross_amount: wajib ada, strictly whole-IDR, dan cocok eksak dengan nominal database (tanpa fallback!)
        if (!$this->isExactAmountMatch($remoteStatus['gross_amount'] ?? null, $donation->nominal)) {
            return false;
        }

        if (!$this->isOptionalIdrCurrencyValid($remoteStatus)) {
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
     * Catatan kompatibilitas D0.1: currency opsional (absent/null diizinkan), tetapi nilai non-null selain 'IDR' ditolak.
     */
    public function validateProviderFailure(array $remoteStatus, Donation $donation): bool
    {
        if (empty($remoteStatus['order_id']) || $remoteStatus['order_id'] !== $donation->order_id) {
            return false;
        }

        if (!$this->isExactAmountMatch($remoteStatus['gross_amount'] ?? null, $donation->nominal)) {
            return false;
        }

        if (!$this->isOptionalIdrCurrencyValid($remoteStatus)) {
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
        if (!$this->isExactAmountMatch($remoteStatus['gross_amount'] ?? null, $donation->nominal)) {
            return false;
        }

        return !empty($remoteStatus['status_code']) &&
            $this->isStatusCodeConsistent((string) $remoteStatus['status_code'], 'pending');
    }

    /**
     * Memvalidasi dan menormalisasi nominal whole-IDR positif secara ketat (ASCII-only, tanpa koersi float/bool/whitespace).
     * Mengembalikan string digit kanonik (mis. '10000') atau null jika tidak valid.
     */
    public function normalizeStrictWholeIdrAmount(mixed $value): ?string
    {
        if (is_int($value)) {
            if ($value <= 0) {
                return null;
            }

            $digits = (string) $value;
            return strlen($digits) <= self::MAX_WHOLE_IDR_DIGITS ? $digits : null;
        }

        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/\A([1-9][0-9]{0,12})(?:\.0{1,2})?\z/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Mengonversi nilai nominal whole-IDR tervalidasi ke integer hanya jika berada dalam rentang aman PHP_INT_MAX.
     */
    public function parseStrictWholeIdrInt(mixed $value): ?int
    {
        $normalized = $this->normalizeStrictWholeIdrAmount($value);
        if ($normalized === null) {
            return null;
        }

        $maxIntStr = (string) PHP_INT_MAX;
        if (
            strlen($normalized) > strlen($maxIntStr)
            || (strlen($normalized) === strlen($maxIntStr) && strcmp($normalized, $maxIntStr) > 0)
        ) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * Membandingkan nilai gross_amount dari provider dengan nominal database secara eksak
     * menggunakan string desimal kanonik whole-IDR tanpa pembulatan float atau truncating cast.
     */
    public function isExactAmountMatch(mixed $grossAmount, mixed $dbNominal): bool
    {
        $normalizedProvider = $this->normalizeStrictWholeIdrAmount($grossAmount);
        if ($normalizedProvider === null) {
            return false;
        }

        $normalizedLocal = $this->normalizeStrictWholeIdrAmount($dbNominal);
        if ($normalizedLocal === null) {
            return false;
        }

        return $normalizedProvider === $normalizedLocal;
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
        mixed $grossAmount = null,
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

            // 2. Validasi exact nominal: wajib untuk seluruh transisi pembayaran terverifikasi/gagal/pending,
            //    recovery settlement, atau kapan pun grossAmount disediakan.
            $requiresVerifiedAmount = $grossAmount !== null
                || $isPreVerifiedRecovery
                || in_array($transactionStatus, ['settlement', 'capture', 'pending', 'expire', 'deny', 'cancel'], true);

            if ($requiresVerifiedAmount && !$this->isExactAmountMatch($grossAmount, $locked->nominal)) {
                Log::error('Midtrans status transition: nominal tidak cocok', [
                    'order_id' => $locked->order_id,
                    'db_nominal' => $locked->nominal,
                    'gross_amount' => is_scalar($grossAmount) ? $grossAmount : gettype($grossAmount),
                ]);
                return [
                    'status' => 'amount_mismatch',
                    'donation_status' => $locked->status,
                    'message' => 'Nominal provider tidak cocok dengan database.',
                ];
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
        $midtransStatus = $this->checkTransactionStatus($donation->order_id, $donation->id ? (int) $donation->id : null);
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
            if (
                array_key_exists('gross_amount', $midtransStatus)
                && $midtransStatus['gross_amount'] !== null
                && !$this->isExactAmountMatch($midtransStatus['gross_amount'], $donation->nominal)
            ) {
                Log::error('Midtrans reconciliation: nominal tidak cocok', [
                    'order_id' => $donation->order_id,
                    'db_nominal' => $donation->nominal,
                    'provider_amount' => is_scalar($midtransStatus['gross_amount']) ? $midtransStatus['gross_amount'] : gettype($midtransStatus['gross_amount']),
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

        if ($transactionStatus === 'pending') {
            if (!$this->validateProviderPending($midtransStatus, $donation)) {
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
