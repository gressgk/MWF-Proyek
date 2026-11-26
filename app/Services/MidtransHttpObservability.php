<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Minimal, strictly redacted observability helper for outbound Midtrans HTTP dispatches.
 */
class MidtransHttpObservability
{
    public const OPERATIONS = [
        'snap_create_transaction' => [
            'method' => 'POST',
            'endpoint_template' => '/snap/v1/transactions',
        ],
        'card_initial_charge' => [
            'method' => 'POST',
            'endpoint_template' => '/v2/charge',
        ],
        'subscription_create' => [
            'method' => 'POST',
            'endpoint_template' => '/v1/subscriptions',
        ],
        'subscription_get' => [
            'method' => 'GET',
            'endpoint_template' => '/v1/subscriptions/{subscription_id}',
        ],
        'subscription_disable' => [
            'method' => 'POST',
            'endpoint_template' => '/v1/subscriptions/{subscription_id}/disable',
        ],
        'subscription_enable' => [
            'method' => 'POST',
            'endpoint_template' => '/v1/subscriptions/{subscription_id}/enable',
        ],
        'subscription_cancel' => [
            'method' => 'POST',
            'endpoint_template' => '/v1/subscriptions/{subscription_id}/cancel',
        ],
        'transaction_get_status' => [
            'method' => 'GET',
            'endpoint_template' => '/v2/{order_id}/status',
        ],
    ];

    public function isEnabled(): bool
    {
        $enabled = (bool) config('services.midtrans.observability.enabled', false);
        if (!$enabled) {
            return false;
        }

        if ((bool) config('services.midtrans.is_production', false)) {
            return false;
        }

        $allowed = (array) config('services.midtrans.observability.allowed_environments', ['local', 'testing']);

        return in_array(app()->environment(), $allowed, true);
    }

    /**
     * Wrap an outbound HTTP dispatch closure without altering payloads, headers, retries, or exceptions.
     */
    public function observe(
        string $operation,
        callable $dispatch,
        ?int $localSubscriptionId = null,
        ?int $localDonationId = null,
        ?string $correlationRef = null
    ): mixed {
        if (!$this->isEnabled()) {
            return $dispatch();
        }

        $meta = self::OPERATIONS[$operation] ?? [
            'method' => 'UNKNOWN',
            'endpoint_template' => '/unknown',
        ];
        $safeOperation = array_key_exists($operation, self::OPERATIONS) ? $operation : 'unknown_operation';
        $safeSubId = ($localSubscriptionId !== null && $localSubscriptionId > 0) ? $localSubscriptionId : null;
        $safeDonId = ($localDonationId !== null && $localDonationId > 0) ? $localDonationId : null;
        $safeRef = $this->sanitizeCorrelationRef($correlationRef);

        $attemptId = (string) Str::uuid();
        $startedNs = hrtime(true);

        $this->safeLog('Midtrans outbound HTTP attempt', [
            'event_type' => 'outbound_attempt',
            'attempt_id' => $attemptId,
            'operation' => $safeOperation,
            'http_method' => $meta['method'],
            'endpoint_template' => $meta['endpoint_template'],
            'http_status' => null,
            'elapsed_ms' => null,
            'local_subscription_id' => $safeSubId,
            'local_donation_id' => $safeDonId,
            'correlation_ref' => $safeRef,
            'transport_outcome' => 'dispatched',
            'timestamp_utc' => $this->utcTimestamp(),
        ]);

        try {
            $response = $dispatch();
        } catch (\Throwable $e) {
            $elapsedMs = round((hrtime(true) - $startedNs) / 1_000_000, 2);

            $this->safeLog('Midtrans outbound HTTP result', [
                'event_type' => 'outbound_result',
                'attempt_id' => $attemptId,
                'operation' => $safeOperation,
                'http_method' => $meta['method'],
                'endpoint_template' => $meta['endpoint_template'],
                'http_status' => null,
                'elapsed_ms' => $elapsedMs,
                'local_subscription_id' => $safeSubId,
                'local_donation_id' => $safeDonId,
                'correlation_ref' => $safeRef,
                'transport_outcome' => $this->classifyThrowable($e),
                'timestamp_utc' => $this->utcTimestamp(),
            ]);

            throw $e;
        }

        $elapsedMs = round((hrtime(true) - $startedNs) / 1_000_000, 2);
        $status = $response instanceof Response ? $response->status() : null;

        $this->safeLog('Midtrans outbound HTTP result', [
            'event_type' => 'outbound_result',
            'attempt_id' => $attemptId,
            'operation' => $safeOperation,
            'http_method' => $meta['method'],
            'endpoint_template' => $meta['endpoint_template'],
            'http_status' => $status,
            'elapsed_ms' => $elapsedMs,
            'local_subscription_id' => $safeSubId,
            'local_donation_id' => $safeDonId,
            'correlation_ref' => $safeRef,
            'transport_outcome' => $status !== null ? $this->classifyHttpStatus($status) : 'unknown_response',
            'timestamp_utc' => $this->utcTimestamp(),
        ]);

        return $response;
    }

    protected function classifyHttpStatus(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return 'http_2xx';
        }
        if ($status >= 300 && $status < 400) {
            return 'http_3xx';
        }
        if ($status >= 400 && $status < 500) {
            return 'http_4xx';
        }
        if ($status >= 500 && $status < 600) {
            return 'http_5xx';
        }

        return 'http_other';
    }

    protected function classifyThrowable(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());
        if (
            str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28')
        ) {
            return 'transport_timeout';
        }

        if ($e instanceof ConnectionException) {
            return 'transport_connection_error';
        }

        return 'transport_unexpected_error';
    }

    protected function sanitizeCorrelationRef(?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        $trimmed = trim($ref);
        if ($trimmed === '' || !preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    protected function utcTimestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    protected function safeLog(string $message, array $context): void
    {
        try {
            $channel = (string) config('services.midtrans.observability.channel', 'midtrans_observability');
            Log::channel($channel)->info($message, $context);
        } catch (\Throwable) {
            // Observability sink failure must never affect financial or management outcomes.
        }
    }
}
