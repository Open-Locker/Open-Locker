<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Arr;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextInterface;

/**
 * Carries W3C trace context across the MQTT boundary through the envelope's
 * optional `traceparent` field.
 *
 * `traceparent` is transport metadata: it is never persisted as domain data,
 * never part of deduplication, and never used for business correlation —
 * `message_id` and `transaction_id` keep those roles.
 */
final class MqttTraceContext
{
    /** Envelope property carrying the W3C trace context. */
    public const FIELD = 'traceparent';

    /**
     * Stamps the active span onto an outbound envelope.
     *
     * With no exporter configured the active span context is invalid, the
     * propagator injects nothing, and the payload goes out exactly as it does
     * today — so tracing stays off on the wire, not just in the exporter.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function inject(array $payload): array
    {
        $traceparent = Tracer::propagationHeaders()[self::FIELD] ?? null;

        if (is_string($traceparent) && $traceparent !== '') {
            $payload[self::FIELD] = $traceparent;
        }

        return $payload;
    }

    /**
     * Remote parent for an inbound envelope, or null to start a fresh trace.
     *
     * The field is optional in every direction, so a missing or malformed
     * `traceparent` is never a reason to reject a message: the consumer span
     * simply becomes a root instead of a continuation.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function extract(array $payload): ?ContextInterface
    {
        $traceparent = Arr::get($payload, self::FIELD);

        if (! is_string($traceparent) || $traceparent === '') {
            return null;
        }

        $context = Tracer::extractContextFromPropagationHeaders([self::FIELD => $traceparent]);

        if ($context === null) {
            return null;
        }

        // A malformed header extracts to the current context rather than
        // failing, so the span context is what says whether it parsed.
        return Span::fromContext($context)->getContext()->isValid() ? $context : null;
    }
}
