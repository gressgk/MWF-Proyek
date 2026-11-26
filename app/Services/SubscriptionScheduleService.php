<?php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Support\Carbon;

class SubscriptionScheduleService
{
    public const DISPLAY_TIMEZONE = 'Asia/Jakarta';

    public const SOURCE_MIDTRANS_SUBSCRIPTION_CREATE = 'midtrans_subscription_create';

    public const SOURCE_MIDTRANS_SUBSCRIPTION_GET = 'midtrans_subscription_get';

    public const MIDTRANS_SUBSCRIPTION_CONTRACT_OFFSET = '+07:00';

    public const TIMEZONE_RESOLUTION_EXPLICIT_OFFSET = 'explicit_offset';

    public const TIMEZONE_RESOLUTION_MIDTRANS_GMT7_CONTRACT = 'midtrans_subscription_gmt7_contract';

    public const MYSQL_TIMESTAMP_MIN_UTC = '1970-01-01T00:00:01Z';

    public const MYSQL_TIMESTAMP_MAX_UTC = '2038-01-19T03:14:07Z';

    /**
     * Batas bawah konservatif untuk persistensi MySQL TIMESTAMP agar aman pada koneksi
     * dengan offset positif hingga +14:00 (termasuk SYSTEM/+07:00 pra-cutover dan +00:00 pasca-cutover).
     */
    public const PERSISTENCE_SAFE_MYSQL_TIMESTAMP_MIN_UTC = '1970-01-02T00:00:00Z';

    private const MYSQL_TIMESTAMP_MIN_EPOCH = 1;

    private const MYSQL_TIMESTAMP_MAX_EPOCH = 2147483647;

    private const PERSISTENCE_SAFE_MYSQL_TIMESTAMP_MIN_EPOCH = 86400;

    private const TRUSTED_MIDTRANS_SUBSCRIPTION_SOURCES = [
        self::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE,
        self::SOURCE_MIDTRANS_SUBSCRIPTION_GET,
    ];

    private const INDONESIAN_MONTHS = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /**
     * Mem-parsing timestamp jadwal dari provider secara ketat.
     * Hanya timestamp dengan offset zona waktu eksplisit (mis. +07:00, +0700, +00:00, Z)
     * dan tanggal kalender valid yang dikonversi ke instan UTC kanonik.
     *
     * @return array{valid: bool, utc: ?Carbon, status: string, reason: ?string}
     */
    public function parseProviderTimestamp(mixed $raw): array
    {
        if ($raw === null) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'unavailable',
                'reason' => 'missing_timestamp',
            ];
        }

        if (!is_string($raw)) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => 'non_string_timestamp',
            ];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => 'empty_timestamp',
            ];
        }

        // Format dengan offset zona waktu eksplisit:
        // YYYY-MM-DD[T ]HH:MM:SS(.frac)?[ ]?(Z|z|+HH:MM|+HHMM|-HH:MM|-HHMM)
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?\s?(Z|z|([+-])(\d{2}):?(\d{2}))$/',
            $trimmed,
            $matches
        ) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $hour = (int) $matches[4];
            $minute = (int) $matches[5];
            $second = (int) $matches[6];

            if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                return [
                    'valid' => false,
                    'utc' => null,
                    'status' => 'invalid',
                    'reason' => 'invalid_calendar_date',
                ];
            }

            $offsetToken = strtoupper($matches[7]);
            if ($offsetToken === 'Z') {
                $tzString = '+00:00';
            } else {
                $sign = $matches[8];
                $offHour = (int) $matches[9];
                $offMin = (int) $matches[10];
                if ($offHour > 14 || $offMin > 59 || ($offHour === 14 && $offMin > 0)) {
                    return [
                        'valid' => false,
                        'utc' => null,
                        'status' => 'invalid',
                        'reason' => 'invalid_timezone_offset',
                    ];
                }
                $tzString = sprintf('%s%02d:%02d', $sign, $offHour, $offMin);
            }

            $utc = Carbon::create($year, $month, $day, $hour, $minute, $second, $tzString)->utc();

            return [
                'valid' => true,
                'utc' => $utc,
                'status' => 'provider_confirmed',
                'reason' => null,
            ];
        }

        // Deteksi format tanggal/waktu tanpa offset eksplisit (offset-free) agar tidak menebak zona waktu.
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?)?$/',
            $trimmed,
            $matches
        ) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $hour = isset($matches[4]) ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) ? (int) $matches[5] : 0;
            $second = isset($matches[6]) ? (int) $matches[6] : 0;

            if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                return [
                    'valid' => false,
                    'utc' => null,
                    'status' => 'invalid',
                    'reason' => 'invalid_calendar_date',
                ];
            }

            return [
                'valid' => false,
                'utc' => null,
                'status' => 'ambiguous_offset_free',
                'reason' => 'missing_timezone_offset',
            ];
        }

        return [
            'valid' => false,
            'utc' => null,
            'status' => 'invalid',
            'reason' => 'malformed_timestamp',
        ];
    }

    /**
     * Mem-parsing timestamp jadwal khusus respons Midtrans Subscription API yang terverifikasi.
     *
     * Kebijakan parsing terikat konteks sumber:
     * 1. Hanya sourceContext dalam allow-list (midtrans_subscription_create, midtrans_subscription_get)
     *    yang diizinkan menafsirkan format offset-free `YYYY-MM-DD HH:MM:SS(.frac)?` sebagai GMT+7 (+07:00 tetap).
     * 2. Jika input sudah membawa offset zona waktu eksplisit, offset tersebut selalu diutamakan.
     * 3. Detik fraksional (1-6 digit) diterima untuk kompatibilitas dan dipotong deterministik ke presisi detik penuh,
     *    selaras dengan parseProviderTimestamp() dan kolom MySQL TIMESTAMP tanpa fraksi.
     * 4. Format date-only, T-separated offset-free, karakter kontrol, maupun konteks tidak dikenal selalu fail-closed.
     * 5. Setiap hasil valid diperiksa terhadap rentang fisik dan keamanan persistensi MySQL TIMESTAMP.
     *
     * @return array{
     *     valid: bool,
     *     utc: ?Carbon,
     *     status: string,
     *     reason: ?string,
     *     timezone_resolution: ?string,
     *     assumed_offset: ?string,
     *     source_context: ?string
     * }
     */
    public function parseMidtransSubscriptionScheduleTimestamp(mixed $raw, string $sourceContext): array
    {
        $isTrustedSource = in_array($sourceContext, self::TRUSTED_MIDTRANS_SUBSCRIPTION_SOURCES, true);
        $recordedContext = $isTrustedSource ? $sourceContext : null;

        if (is_string($raw) && preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => 'malformed_timestamp',
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        $generic = $this->parseProviderTimestamp($raw);

        if ($generic['valid'] === true && $generic['utc'] instanceof Carbon) {
            $rangeCheck = $this->validatePersistenceRange($generic['utc']);
            if (!$rangeCheck['valid']) {
                return [
                    'valid' => false,
                    'utc' => null,
                    'status' => 'invalid',
                    'reason' => $rangeCheck['reason'],
                    'timezone_resolution' => null,
                    'assumed_offset' => null,
                    'source_context' => $recordedContext,
                ];
            }

            return [
                'valid' => true,
                'utc' => $generic['utc'],
                'status' => 'provider_confirmed',
                'reason' => null,
                'timezone_resolution' => self::TIMEZONE_RESOLUTION_EXPLICIT_OFFSET,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        if (!$isTrustedSource || !is_string($raw) || $generic['status'] !== 'ambiguous_offset_free') {
            return array_merge($generic, [
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ]);
        }

        $trimmed = trim($raw, ' ');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $trimmed) === 1) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => 'date_only_timestamp',
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/D', $trimmed, $matches) !== 1) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'ambiguous_offset_free',
                'reason' => 'unsupported_offset_free_format',
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        $hour = (int) $matches[4];
        $minute = (int) $matches[5];
        $second = (int) $matches[6];

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => 'invalid_calendar_date',
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        $utc = Carbon::create($year, $month, $day, $hour, $minute, $second, self::MIDTRANS_SUBSCRIPTION_CONTRACT_OFFSET)->utc();

        $rangeCheck = $this->validatePersistenceRange($utc);
        if (!$rangeCheck['valid']) {
            return [
                'valid' => false,
                'utc' => null,
                'status' => 'invalid',
                'reason' => $rangeCheck['reason'],
                'timezone_resolution' => null,
                'assumed_offset' => null,
                'source_context' => $recordedContext,
            ];
        }

        return [
            'valid' => true,
            'utc' => $utc,
            'status' => 'provider_confirmed',
            'reason' => null,
            'timezone_resolution' => self::TIMEZONE_RESOLUTION_MIDTRANS_GMT7_CONTRACT,
            'assumed_offset' => self::MIDTRANS_SUBSCRIPTION_CONTRACT_OFFSET,
            'source_context' => $recordedContext,
        ];
    }

    /**
     * Memvalidasi instan UTC terhadap rentang fisik dan keamanan persistensi kolom MySQL TIMESTAMP.
     *
     * @return array{valid: bool, reason: ?string}
     */
    private function validatePersistenceRange(Carbon $utc): array
    {
        $epoch = $utc->getTimestamp();

        if ($epoch < self::MYSQL_TIMESTAMP_MIN_EPOCH || $epoch > self::MYSQL_TIMESTAMP_MAX_EPOCH) {
            return [
                'valid' => false,
                'reason' => 'out_of_mysql_timestamp_range',
            ];
        }

        if ($epoch < self::PERSISTENCE_SAFE_MYSQL_TIMESTAMP_MIN_EPOCH) {
            return [
                'valid' => false,
                'reason' => 'unsafe_mysql_timestamp_lower_boundary',
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
        ];
    }

    /**
     * Menghitung waktu mulai siklus berikutnya dalam UTC dari waktu dasar UTC.
     * Bulanan menggunakan addMonthNoOverflow() agar akhir bulan (mis. 31 Jan) tidak melompat ke Maret.
     */
    public function calculateNextStartTime(Carbon $baseUtc, string $frequency): Carbon
    {
        $utc = $baseUtc->copy()->utc();

        return $frequency === 'mingguan'
            ? $utc->addWeek()
            : $utc->addMonthNoOverflow();
    }

    /**
     * Memformat start_time keluar untuk Midtrans API (yyyy-MM-dd HH:mm:ss O dengan offset UTC +0000 eksplisit).
     */
    public function formatOutboundStartTime(Carbon $utc): string
    {
        return $utc->copy()->utc()->format('Y-m-d H:i:s O');
    }

    /**
     * Membangun metadata provenance jadwal untuk disimpan pada subscriptions.metadata['schedule'].
     *
     * @param array{valid: bool, utc: ?Carbon, status: string, reason: ?string, timezone_resolution?: ?string, assumed_offset?: ?string, source_context?: ?string} $parsed
     */
    public function buildScheduleMetadata(array $parsed, ?Carbon $estimatedStartUtc = null): array
    {
        $meta = [
            'next_execution_source' => $parsed['status'],
            'next_execution_utc' => $parsed['valid'] && $parsed['utc'] instanceof Carbon
                ? $parsed['utc']->copy()->utc()->toIso8601ZuluString()
                : null,
            'freshness' => $parsed['valid'] ? 'fresh' : 'unverified',
            'last_refresh_status' => $parsed['status'],
            'synced_at' => now()->utc()->toIso8601ZuluString(),
        ];

        if ($parsed['valid']) {
            $tzResolution = $parsed['timezone_resolution'] ?? null;
            if (in_array($tzResolution, [self::TIMEZONE_RESOLUTION_EXPLICIT_OFFSET, self::TIMEZONE_RESOLUTION_MIDTRANS_GMT7_CONTRACT], true)) {
                $meta['timezone_resolution'] = $tzResolution;
                $meta['assumed_offset'] = $tzResolution === self::TIMEZONE_RESOLUTION_MIDTRANS_GMT7_CONTRACT
                    ? self::MIDTRANS_SUBSCRIPTION_CONTRACT_OFFSET
                    : null;
            }

            $sourceContext = $parsed['source_context'] ?? null;
            if (is_string($sourceContext) && in_array($sourceContext, self::TRUSTED_MIDTRANS_SUBSCRIPTION_SOURCES, true)) {
                $meta['source_context'] = $sourceContext;
            }
        }

        if (!$parsed['valid'] && ($parsed['reason'] ?? null) !== null) {
            $meta['reason'] = $parsed['reason'];
        }

        if ($estimatedStartUtc !== null && !$parsed['valid']) {
            $meta['estimated_start_time_utc'] = $estimatedStartUtc->copy()->utc()->toIso8601ZuluString();
        }

        return $meta;
    }

    /**
     * Mempertahankan bukti jadwal terkonfirmasi terakhir sambil menandainya sebagai stale_preserved
     * ketika respons provider terbaru tidak memuat jadwal yang valid.
     *
     * @param array<string, mixed> $existingScheduleMeta
     * @param array{valid: bool, utc: ?Carbon, status: string, reason: ?string} $failedParsed
     * @return array<string, mixed>
     */
    public function markPreservedScheduleStale(array $existingScheduleMeta, array $failedParsed): array
    {
        $updated = $existingScheduleMeta;
        $updated['next_execution_source'] = 'provider_confirmed';
        $updated['freshness'] = 'stale_preserved';
        $updated['last_refresh_status'] = $failedParsed['status'];
        $updated['last_refresh_attempted_at'] = now()->utc()->toIso8601ZuluString();
        if (($failedParsed['reason'] ?? null) !== null) {
            $updated['last_refresh_reason'] = $failedParsed['reason'];
        }

        return $updated;
    }

    /**
     * Memformat instan UTC terkonfirmasi ke tampilan WIB (Asia/Jakarta).
     */
    public function formatWibDisplay(Carbon $utc): string
    {
        $wib = $utc->copy()->utc()->setTimezone(self::DISPLAY_TIMEZONE);
        $monthName = self::INDONESIAN_MONTHS[$wib->month] ?? $wib->format('m');

        return sprintf(
            '%d %s %04d, %02d:%02d:%02d WIB',
            $wib->day,
            $monthName,
            $wib->year,
            $wib->hour,
            $wib->minute,
            $wib->second
        );
    }

    /**
     * Memetakan jadwal Subscription untuk serialisasi JSON API manajemen dan checkout (TZ6 / E5.2 4.2).
     * Tidak pernah mempercayai kolom Eloquent next_execution_at tanpa canonical next_execution_utc tervalidasi,
     * dan tidak pernah menyatakan jadwal sebagai jaminan keberhasilan penagihan.
     *
     * @return array{next_execution_at: ?string, schedule: array<string, mixed>}
     */
    public function serializeForApi(Subscription $subscription): array
    {
        $meta = is_array($subscription->metadata) ? $subscription->metadata : [];
        $scheduleMeta = is_array($meta['schedule'] ?? null) ? $meta['schedule'] : [];
        $source = $scheduleMeta['next_execution_source'] ?? null;
        $lastSyncedAt = is_string($scheduleMeta['synced_at'] ?? null) && trim($scheduleMeta['synced_at']) !== ''
            ? $scheduleMeta['synced_at']
            : null;

        $localStatus = strtolower(trim((string) $subscription->status));
        $mgmtState = $subscription->management_state;

        if ($mgmtState === 'cancelled' || $localStatus === 'cancelled') {
            return [
                'next_execution_at' => null,
                'schedule' => [
                    'status' => 'cancelled_no_upcoming_charge',
                    'next_execution_at_utc' => null,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => 'none',
                    'is_past' => false,
                    'is_displayable_upcoming' => false,
                    'display_wib' => null,
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        $canonicalParsed = ['valid' => false, 'utc' => null, 'status' => 'unverified', 'reason' => null];
        if ($source === 'provider_confirmed') {
            $canonicalParsed = $this->parseProviderTimestamp($scheduleMeta['next_execution_utc'] ?? null);
        }

        $canonicalUtc = $canonicalParsed['valid'] && $canonicalParsed['utc'] instanceof Carbon
            ? $canonicalParsed['utc']->copy()->utc()
            : null;
        $canonicalIsoUtc = $canonicalUtc?->toIso8601ZuluString();
        $isPastCanonical = $canonicalUtc !== null && $canonicalUtc->lte(now()->utc());
        $isStalePreserved = ($scheduleMeta['freshness'] ?? 'fresh') === 'stale_preserved';

        $resolvedCanonicalFreshness = $canonicalUtc === null
            ? 'unverified'
            : ($isPastCanonical ? 'past' : ($isStalePreserved ? 'stale_preserved' : 'fresh'));

        if ($mgmtState === 'paused') {
            return [
                'next_execution_at' => $canonicalIsoUtc,
                'schedule' => [
                    'status' => 'paused_not_scheduled',
                    'next_execution_at_utc' => $canonicalIsoUtc,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => $resolvedCanonicalFreshness,
                    'is_past' => $isPastCanonical,
                    'is_displayable_upcoming' => false,
                    'display_wib' => null,
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        if ($mgmtState === 'reconciliation') {
            return [
                'next_execution_at' => $canonicalIsoUtc,
                'schedule' => [
                    'status' => 'pending_reconciliation',
                    'next_execution_at_utc' => $canonicalIsoUtc,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => $resolvedCanonicalFreshness,
                    'is_past' => $isPastCanonical,
                    'is_displayable_upcoming' => false,
                    'display_wib' => null,
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        if ($localStatus !== 'active') {
            return [
                'next_execution_at' => null,
                'schedule' => [
                    'status' => 'awaiting_verification',
                    'next_execution_at_utc' => null,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => 'unverified',
                    'is_past' => false,
                    'is_displayable_upcoming' => false,
                    'display_wib' => null,
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        if ($canonicalUtc !== null && $canonicalIsoUtc !== null) {
            if ($isPastCanonical) {
                return [
                    'next_execution_at' => $canonicalIsoUtc,
                    'schedule' => [
                        'status' => 'past_awaiting_refresh',
                        'next_execution_at_utc' => $canonicalIsoUtc,
                        'display_timezone' => self::DISPLAY_TIMEZONE,
                        'is_upcoming_billing_guaranteed' => false,
                        'freshness' => 'past',
                        'is_past' => true,
                        'is_displayable_upcoming' => false,
                        'display_wib' => null,
                        'last_synced_at' => $lastSyncedAt,
                    ],
                ];
            }

            if ($isStalePreserved) {
                return [
                    'next_execution_at' => $canonicalIsoUtc,
                    'schedule' => [
                        'status' => 'stale_preserved',
                        'next_execution_at_utc' => $canonicalIsoUtc,
                        'display_timezone' => self::DISPLAY_TIMEZONE,
                        'is_upcoming_billing_guaranteed' => false,
                        'freshness' => 'stale_preserved',
                        'is_past' => false,
                        'is_displayable_upcoming' => false,
                        'display_wib' => null,
                        'last_synced_at' => $lastSyncedAt,
                    ],
                ];
            }

            return [
                'next_execution_at' => $canonicalIsoUtc,
                'schedule' => [
                    'status' => 'confirmed',
                    'next_execution_at_utc' => $canonicalIsoUtc,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => 'fresh',
                    'is_past' => false,
                    'is_displayable_upcoming' => true,
                    'display_wib' => $this->formatWibDisplay($canonicalUtc),
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        if ($subscription->next_execution_at !== null) {
            return [
                'next_execution_at' => null,
                'schedule' => [
                    'status' => 'historical_unverified',
                    'next_execution_at_utc' => null,
                    'display_timezone' => self::DISPLAY_TIMEZONE,
                    'is_upcoming_billing_guaranteed' => false,
                    'freshness' => 'unverified',
                    'is_past' => false,
                    'is_displayable_upcoming' => false,
                    'display_wib' => null,
                    'last_synced_at' => $lastSyncedAt,
                ],
            ];
        }

        $unavailableStatus = is_string($source) && $source !== '' && $source !== 'provider_confirmed'
            ? $source
            : 'unavailable';

        return [
            'next_execution_at' => null,
            'schedule' => [
                'status' => $unavailableStatus,
                'next_execution_at_utc' => null,
                'display_timezone' => self::DISPLAY_TIMEZONE,
                'is_upcoming_billing_guaranteed' => false,
                'freshness' => 'unverified',
                'is_past' => false,
                'is_displayable_upcoming' => false,
                'display_wib' => null,
                'last_synced_at' => $lastSyncedAt,
            ],
        ];
    }
}
