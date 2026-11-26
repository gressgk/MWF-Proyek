<?php

use App\Services\SubscriptionScheduleService;
use Illuminate\Support\Carbon;

test('TZ: parses explicit +07:00, +0700, +00:00, and Z timestamps to canonical UTC instants', function (string $input, string $expectedUtcIso, string $expectedWib) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseProviderTimestamp($input);

    expect($parsed['valid'])->toBeTrue();
    expect($parsed['status'])->toBe('provider_confirmed');
    expect($parsed['reason'])->toBeNull();
    expect($parsed['utc'])->toBeInstanceOf(Carbon::class);
    expect($parsed['utc']->timezoneName)->toBe('UTC');
    expect($parsed['utc']->toIso8601ZuluString())->toBe($expectedUtcIso);
    expect($service->formatWibDisplay($parsed['utc']))->toBe($expectedWib);
})->with([
    '+07:00 standard' => ['2026-12-24T13:23:36+07:00', '2026-12-24T06:23:36Z', '24 Desember 2026, 13:23:36 WIB'],
    '+0700 space format' => ['2026-12-24 13:23:36 +0700', '2026-12-24T06:23:36Z', '24 Desember 2026, 13:23:36 WIB'],
    '+0700 T format' => ['2026-12-24T13:23:36+0700', '2026-12-24T06:23:36Z', '24 Desember 2026, 13:23:36 WIB'],
    '+00:00 UTC explicit' => ['2026-12-24T06:23:36+00:00', '2026-12-24T06:23:36Z', '24 Desember 2026, 13:23:36 WIB'],
    'Z suffix with micros' => ['2026-12-24T06:23:36.000000Z', '2026-12-24T06:23:36Z', '24 Desember 2026, 13:23:36 WIB'],
    'midnight crossing WIB to UTC' => ['2026-12-24T00:30:00+07:00', '2026-12-23T17:30:00Z', '24 Desember 2026, 00:30:00 WIB'],
    'month and year crossing WIB to UTC' => ['2027-01-01T02:00:00+07:00', '2026-12-31T19:00:00Z', '1 Januari 2027, 02:00:00 WIB'],
    'leap day WIB to UTC' => ['2028-02-29T01:00:00+07:00', '2028-02-28T18:00:00Z', '29 Februari 2028, 01:00:00 WIB'],
]);

test('TZ: rejects offset-free, null, malformed, and invalid calendar timestamps safely without guessing timezone', function (mixed $raw, string $expectedStatus) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseProviderTimestamp($raw);

    expect($parsed['valid'])->toBeFalse();
    expect($parsed['utc'])->toBeNull();
    expect($parsed['status'])->toBe($expectedStatus);
})->with([
    'null timestamp' => [null, 'unavailable'],
    'empty string' => ['', 'invalid'],
    'whitespace' => ['   ', 'invalid'],
    'integer' => [1700000000, 'invalid'],
    'array' => [['2026-12-24T13:23:36+07:00'], 'invalid'],
    'offset-free datetime space' => ['2026-12-24 13:23:36', 'ambiguous_offset_free'],
    'offset-free datetime T micros' => ['2026-12-24T13:23:36.803677', 'ambiguous_offset_free'],
    'offset-free date only' => ['2026-12-24', 'ambiguous_offset_free'],
    'invalid calendar non-leap feb 29 with offset' => ['2026-02-29T10:00:00+07:00', 'invalid'],
    'invalid calendar feb 30' => ['2026-02-30T10:00:00Z', 'invalid'],
    'invalid month 13' => ['2026-13-01T00:00:00Z', 'invalid'],
    'invalid hour 24' => ['2026-12-24T24:00:00Z', 'invalid'],
    'invalid offset +25:00' => ['2026-12-24T10:00:00+25:00', 'invalid'],
    'garbage text' => ['not-a-timestamp', 'invalid'],
]);

test('TZ: calculates weekly and monthly start schedules without month-end overflow and formats outbound UTC +0000', function () {
    $service = new SubscriptionScheduleService();

    // Weekly from midnight UTC
    $weekly = $service->calculateNextStartTime(Carbon::parse('2026-11-26 02:30:00', 'UTC'), 'mingguan');
    expect($weekly->toIso8601ZuluString())->toBe('2026-12-03T02:30:00Z');
    expect($service->formatOutboundStartTime($weekly))->toBe('2026-12-03 02:30:00 +0000');

    // Monthly standard
    $monthly = $service->calculateNextStartTime(Carbon::parse('2026-11-26 02:30:00', 'UTC'), 'bulanan');
    expect($monthly->toIso8601ZuluString())->toBe('2026-12-26T02:30:00Z');

    // Non-leap month-end: Jan 31 2026 -> Feb 28 2026 (no overflow to March 3)
    $nonLeapEnd = $service->calculateNextStartTime(Carbon::parse('2026-01-31 18:00:00', 'UTC'), 'bulanan');
    expect($nonLeapEnd->toIso8601ZuluString())->toBe('2026-02-28T18:00:00Z');

    // Leap month-end: Jan 31 2028 -> Feb 29 2028
    $leapEnd = $service->calculateNextStartTime(Carbon::parse('2028-01-31 18:00:00', 'UTC'), 'bulanan');
    expect($leapEnd->toIso8601ZuluString())->toBe('2028-02-29T18:00:00Z');
});

// ============================================================
// E5.5-C.1: CONTEXT-AWARE MIDTRANS SUBSCRIPTION SCHEDULE PARSER
// ============================================================

test('E5.5-C.1: generic parser remains unchanged and strict for offset-free strings', function (string $input) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseProviderTimestamp($input);

    expect($parsed['valid'])->toBeFalse();
    expect($parsed['utc'])->toBeNull();
    expect($parsed['status'])->toBe('ambiguous_offset_free');
    expect($parsed['reason'])->toBe('missing_timezone_offset');
})->with([
    'space separated datetime' => ['2026-12-03 14:27:12'],
    'T separated datetime' => ['2026-12-03T14:27:12'],
    'date only' => ['2026-12-03'],
]);

test('E5.5-C.1: trusted Midtrans Create and Get contexts parse documented offset-free GMT+7 format including sandbox evidence and UTC rollover', function (
    string $input,
    string $sourceContext,
    string $expectedUtcIso,
    int $expectedEpoch,
    string $expectedWib
) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseMidtransSubscriptionScheduleTimestamp($input, $sourceContext);

    expect($parsed['valid'])->toBeTrue();
    expect($parsed['status'])->toBe('provider_confirmed');
    expect($parsed['reason'])->toBeNull();
    expect($parsed['utc'])->toBeInstanceOf(Carbon::class);
    expect($parsed['utc']->timezoneName)->toBe('UTC');
    expect($parsed['utc']->toIso8601ZuluString())->toBe($expectedUtcIso);
    expect($parsed['utc']->getTimestamp())->toBe($expectedEpoch);
    expect($parsed['timezone_resolution'])->toBe('midtrans_subscription_gmt7_contract');
    expect($parsed['assumed_offset'])->toBe('+07:00');
    expect($parsed['source_context'])->toBe($sourceContext);
    expect($service->formatWibDisplay($parsed['utc']))->toBe($expectedWib);
})->with([
    'sandbox Sub C create evidence' => [
        '2026-12-03 14:27:12',
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE,
        '2026-12-03T07:27:12Z',
        1796282832,
        '3 Desember 2026, 14:27:12 WIB',
    ],
    'trusted get context' => [
        '2026-12-24 13:23:36',
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET,
        '2026-12-24T06:23:36Z',
        1798093416,
        '24 Desember 2026, 13:23:36 WIB',
    ],
    'midnight UTC rollover across date boundary' => [
        '2026-12-03 02:15:00',
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET,
        '2026-12-02T19:15:00Z',
        1796238900,
        '3 Desember 2026, 02:15:00 WIB',
    ],
    'leap year Feb 29 valid' => [
        '2028-02-29 14:27:12',
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE,
        '2028-02-29T07:27:12Z',
        1835422032,
        '29 Februari 2028, 14:27:12 WIB',
    ],
    'fractional seconds 1-6 digits truncated deterministically' => [
        '2026-12-03 14:27:12.654321',
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET,
        '2026-12-03T07:27:12Z',
        1796282832,
        '3 Desember 2026, 14:27:12 WIB',
    ],
]);

test('E5.5-C.1: explicit timezone offsets take precedence over GMT+7 fallback across all trusted contexts', function (string $input, string $expectedUtcIso) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseMidtransSubscriptionScheduleTimestamp(
        $input,
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
    );

    expect($parsed['valid'])->toBeTrue();
    expect($parsed['status'])->toBe('provider_confirmed');
    expect($parsed['reason'])->toBeNull();
    expect($parsed['utc']->toIso8601ZuluString())->toBe($expectedUtcIso);
    expect($parsed['timezone_resolution'])->toBe('explicit_offset');
    expect($parsed['assumed_offset'])->toBeNull();
    expect($parsed['source_context'])->toBe('midtrans_subscription_get');
})->with([
    'explicit Z' => ['2026-12-03T07:27:12Z', '2026-12-03T07:27:12Z'],
    'explicit +00:00' => ['2026-12-03T07:27:12+00:00', '2026-12-03T07:27:12Z'],
    'explicit +07:00' => ['2026-12-03T14:27:12+07:00', '2026-12-03T07:27:12Z'],
    'explicit +0700' => ['2026-12-03 14:27:12 +0700', '2026-12-03T07:27:12Z'],
    'explicit non-WIB +08:00' => ['2026-12-03T15:27:12+08:00', '2026-12-03T07:27:12Z'],
    'explicit non-WIB -05:00' => ['2026-12-03T02:27:12-05:00', '2026-12-03T07:27:12Z'],
]);

test('E5.5-C.1: unknown source context fails closed on offset-free strings and preserves explicit-offset support without echoing context', function () {
    $service = new SubscriptionScheduleService();

    $offsetFree = $service->parseMidtransSubscriptionScheduleTimestamp('2026-12-03 14:27:12', 'unknown_webhook');
    expect($offsetFree['valid'])->toBeFalse();
    expect($offsetFree['utc'])->toBeNull();
    expect($offsetFree['status'])->toBe('ambiguous_offset_free');
    expect($offsetFree['reason'])->toBe('missing_timezone_offset');
    expect($offsetFree['timezone_resolution'])->toBeNull();
    expect($offsetFree['assumed_offset'])->toBeNull();
    expect($offsetFree['source_context'])->toBeNull();

    $explicit = $service->parseMidtransSubscriptionScheduleTimestamp('2026-12-03T07:27:12Z', 'unknown_webhook');
    expect($explicit['valid'])->toBeTrue();
    expect($explicit['utc']->toIso8601ZuluString())->toBe('2026-12-03T07:27:12Z');
    expect($explicit['timezone_resolution'])->toBe('explicit_offset');
    expect($explicit['assumed_offset'])->toBeNull();
    expect($explicit['source_context'])->toBeNull();
});

test('E5.5-C.1: rejects date-only, T-separated offset-free, invalid calendars, invalid offsets, nulls, control chars, and trailing garbage', function (
    mixed $raw,
    string $expectedStatus,
    string $expectedReason
) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseMidtransSubscriptionScheduleTimestamp(
        $raw,
        SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE
    );

    expect($parsed['valid'])->toBeFalse();
    expect($parsed['utc'])->toBeNull();
    expect($parsed['status'])->toBe($expectedStatus);
    expect($parsed['reason'])->toBe($expectedReason);
    expect($parsed['timezone_resolution'])->toBeNull();
    expect($parsed['assumed_offset'])->toBeNull();
})->with([
    'date only rejected' => ['2026-12-03', 'invalid', 'date_only_timestamp'],
    'T-separated offset-free remains unconfirmed' => ['2026-12-03T14:27:12', 'ambiguous_offset_free', 'unsupported_offset_free_format'],
    'non-leap Feb 29' => ['2026-02-29 14:27:12', 'invalid', 'invalid_calendar_date'],
    'month 13' => ['2026-13-01 10:00:00', 'invalid', 'invalid_calendar_date'],
    'Feb 30' => ['2026-02-30 10:00:00', 'invalid', 'invalid_calendar_date'],
    'hour 24' => ['2026-12-03 24:00:00', 'invalid', 'invalid_calendar_date'],
    'minute 60' => ['2026-12-03 14:60:00', 'invalid', 'invalid_calendar_date'],
    'leap second 60 rejected' => ['2026-12-03 23:59:60', 'invalid', 'invalid_calendar_date'],
    'invalid offset +25:00' => ['2026-12-03T10:00:00+25:00', 'invalid', 'invalid_timezone_offset'],
    'invalid offset +14:30' => ['2026-12-03T10:00:00+14:30', 'invalid', 'invalid_timezone_offset'],
    'null input' => [null, 'unavailable', 'missing_timestamp'],
    'non-string int' => [1796282832, 'invalid', 'non_string_timestamp'],
    'non-string array' => [['2026-12-03 14:27:12'], 'invalid', 'non_string_timestamp'],
    'empty string' => ['', 'invalid', 'empty_timestamp'],
    'whitespace only' => ['   ', 'invalid', 'empty_timestamp'],
    'fractional 7 digits rejected' => ['2026-12-03 14:27:12.1234567', 'invalid', 'malformed_timestamp'],
    'trailing garbage' => ['2026-12-03 14:27:12 UTC', 'invalid', 'malformed_timestamp'],
    'embedded newline rejected' => ["2026-12-03 14:27:12\n", 'invalid', 'malformed_timestamp'],
    'embedded tab rejected' => ["2026-12-03\t14:27:12", 'invalid', 'malformed_timestamp'],
    'natural language rejected' => ['next week', 'invalid', 'malformed_timestamp'],
]);

test('E5.5-C.1: enforces canonical MySQL TIMESTAMP range and fail-closed SYSTEM-session lower boundary floor', function (
    string $raw,
    string $sourceContext,
    bool $expectedValid,
    ?string $expectedUtcIso,
    ?string $expectedReason
) {
    $service = new SubscriptionScheduleService();
    $parsed = $service->parseMidtransSubscriptionScheduleTimestamp($raw, $sourceContext);

    expect($parsed['valid'])->toBe($expectedValid);
    if ($expectedValid) {
        expect($parsed['utc']->toIso8601ZuluString())->toBe($expectedUtcIso);
        expect($parsed['reason'])->toBeNull();
    } else {
        expect($parsed['utc'])->toBeNull();
        expect($parsed['status'])->toBe('invalid');
        expect($parsed['reason'])->toBe($expectedReason);
    }
})->with([
    'epoch 0 canonical out of range' => ['1970-01-01T00:00:00Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    '1969 pre-epoch explicit' => ['1969-12-31T23:59:59Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    '1970-01-01 GMT+7 maps to 1969 UTC' => ['1970-01-01 06:59:59', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    '1970-01-01 07:00:00 GMT+7 maps to epoch 0' => ['1970-01-01 07:00:00', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    'lower boundary 1970-01-01T00:00:01Z unsafe under SYSTEM/+07:00' => ['1970-01-01T00:00:01Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'unsafe_mysql_timestamp_lower_boundary'],
    'lower boundary 1970-01-01 14:00:00 GMT+7 (07:00 UTC) unsafe under SYSTEM/+07:00' => ['1970-01-01 14:00:00', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'unsafe_mysql_timestamp_lower_boundary'],
    'lower boundary 1970-01-01T23:59:59Z below 24h safe floor' => ['1970-01-01T23:59:59Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'unsafe_mysql_timestamp_lower_boundary'],
    'safe lower floor 1970-01-02T00:00:00Z via GMT+7 accepted' => ['1970-01-02 07:00:00', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, true, '1970-01-02T00:00:00Z', null],
    'safe lower floor 1970-01-02T00:00:00Z explicit accepted' => ['1970-01-02T00:00:00Z', 'unknown_source', true, '1970-01-02T00:00:00Z', null],
    'exact upper bound 2038-01-19T03:14:07Z explicit accepted' => ['2038-01-19T03:14:07Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, true, '2038-01-19T03:14:07Z', null],
    'exact upper bound 2038-01-19 10:14:07 GMT+7 accepted' => ['2038-01-19 10:14:07', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, true, '2038-01-19T03:14:07Z', null],
    '1s past upper bound explicit rejected' => ['2038-01-19T03:14:08Z', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    '1s past upper bound GMT+7 rejected' => ['2038-01-19 10:14:08', SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET, false, null, 'out_of_mysql_timestamp_range'],
    'far future 2099 explicit rejected' => ['2099-01-01T00:00:00Z', 'unknown_source', false, null, 'out_of_mysql_timestamp_range'],
]);

test('E5.5-C.1: buildScheduleMetadata and markPreservedScheduleStale record and preserve allow-listed provenance accurately', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 07:27:12', 'UTC'));
    try {
        $service = new SubscriptionScheduleService();

        $parsedGmt7 = $service->parseMidtransSubscriptionScheduleTimestamp(
            '2026-12-03 14:27:12',
            SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_CREATE
        );
        $metaGmt7 = $service->buildScheduleMetadata($parsedGmt7);
        expect($metaGmt7)->toBe([
            'next_execution_source' => 'provider_confirmed',
            'next_execution_utc' => '2026-12-03T07:27:12Z',
            'freshness' => 'fresh',
            'last_refresh_status' => 'provider_confirmed',
            'synced_at' => '2026-11-26T07:27:12Z',
            'timezone_resolution' => 'midtrans_subscription_gmt7_contract',
            'assumed_offset' => '+07:00',
            'source_context' => 'midtrans_subscription_create',
        ]);

        $parsedExplicit = $service->parseMidtransSubscriptionScheduleTimestamp(
            '2026-12-10T05:00:00Z',
            SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
        );
        $metaExplicit = $service->buildScheduleMetadata($parsedExplicit);
        expect($metaExplicit['timezone_resolution'])->toBe('explicit_offset');
        expect($metaExplicit['assumed_offset'])->toBeNull();
        expect($metaExplicit['source_context'])->toBe('midtrans_subscription_get');

        Carbon::setTestNow(Carbon::parse('2026-11-27 01:00:00', 'UTC'));
        $failedRefresh = $service->parseMidtransSubscriptionScheduleTimestamp(
            '2026-12-10',
            SubscriptionScheduleService::SOURCE_MIDTRANS_SUBSCRIPTION_GET
        );
        $staleMeta = $service->markPreservedScheduleStale($metaGmt7, $failedRefresh);
        expect($staleMeta['next_execution_source'])->toBe('provider_confirmed');
        expect($staleMeta['next_execution_utc'])->toBe('2026-12-03T07:27:12Z');
        expect($staleMeta['timezone_resolution'])->toBe('midtrans_subscription_gmt7_contract');
        expect($staleMeta['assumed_offset'])->toBe('+07:00');
        expect($staleMeta['source_context'])->toBe('midtrans_subscription_create');
        expect($staleMeta['freshness'])->toBe('stale_preserved');
        expect($staleMeta['last_refresh_status'])->toBe('invalid');
        expect($staleMeta['last_refresh_reason'])->toBe('date_only_timestamp');
        expect($staleMeta['last_refresh_attempted_at'])->toBe('2026-11-27T01:00:00Z');
    } finally {
        Carbon::setTestNow();
    }
});

test('E5.5-C.1: serializeForApi validates canonical UTC strictly, formats WIB deterministically, and never trusts offset-free or historical columns', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 08:00:00', 'UTC'));
    try {
        $service = new SubscriptionScheduleService();

        $makeInMemorySubscription = static function (array $attrs): \App\Models\Subscription {
            $model = new class extends \App\Models\Subscription {
                public function getDateFormat(): string
                {
                    return 'Y-m-d H:i:s';
                }
            };
            $model->forceFill($attrs);

            return $model;
        };

        $activeFuture = $makeInMemorySubscription([
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-03 07:27:12',
            'metadata' => [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03T07:27:12Z',
                    'freshness' => 'fresh',
                    'synced_at' => '2026-11-26T07:27:12Z',
                ],
            ],
        ]);
        $outFuture = $service->serializeForApi($activeFuture);
        expect($outFuture['next_execution_at'])->toBe('2026-12-03T07:27:12Z');
        expect($outFuture['schedule']['status'])->toBe('confirmed');
        expect($outFuture['schedule']['is_displayable_upcoming'])->toBeTrue();
        expect($outFuture['schedule']['display_wib'])->toBe('3 Desember 2026, 14:27:12 WIB');
        expect($outFuture['schedule']['is_upcoming_billing_guaranteed'])->toBeFalse();

        // Offset-free value stored in canonical next_execution_utc must STILL be rejected!
        $corruptedCanonical = $makeInMemorySubscription([
            'status' => 'active',
            'management_state' => 'active',
            'next_execution_at' => '2026-12-03 14:27:12',
            'metadata' => [
                'schedule' => [
                    'next_execution_source' => 'provider_confirmed',
                    'next_execution_utc' => '2026-12-03 14:27:12',
                    'freshness' => 'fresh',
                ],
            ],
        ]);
        $outCorrupted = $service->serializeForApi($corruptedCanonical);
        expect($outCorrupted['next_execution_at'])->toBeNull();
        expect($outCorrupted['schedule']['status'])->toBe('historical_unverified');
        expect($outCorrupted['schedule']['is_displayable_upcoming'])->toBeFalse();
        expect($outCorrupted['schedule']['display_wib'])->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});
