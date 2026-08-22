<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Derives span attributes from an MQTT topic and envelope.
 *
 * Only the fields listed in {@see TraceAttributes} are read. Payload bodies are
 * never attached to spans.
 */
final class MqttSpanAttributes
{
    /**
     * Registration topics carry the provisioning token in their path, which must
     * never reach a span. Templating it also keeps the attribute low-cardinality,
     * which is what tracing backends want from a destination.
     */
    private const REGISTRATION_PREFIX = 'locker/register/';

    private const REGISTRATION_DESTINATION = 'locker/register/{token}';

    /**
     * Span-safe destination for a topic: use for both the attribute and the span
     * name so a secret cannot leak through either.
     */
    public static function destination(string $topic): string
    {
        return str_starts_with($topic, self::REGISTRATION_PREFIX)
            ? self::REGISTRATION_DESTINATION
            : $topic;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromMessage(string $topic, array $payload): array
    {
        $attributes = [
            TraceAttributes::MESSAGING_SYSTEM => TraceAttributes::MESSAGING_SYSTEM_MQTT,
            TraceAttributes::MESSAGING_DESTINATION => self::destination($topic),
        ];

        $scalars = [
            TraceAttributes::MESSAGING_MESSAGE_ID => Arr::get($payload, 'message_id'),
            TraceAttributes::TRANSACTION_ID => Arr::get($payload, 'transaction_id'),
            TraceAttributes::COMMAND_ID => Arr::get($payload, 'command_id'),
            TraceAttributes::ACTION => Arr::get($payload, 'action'),
            TraceAttributes::EVENT => Arr::get($payload, 'event'),
            TraceAttributes::COMPARTMENT_NUMBER => Arr::get($payload, 'data.compartment_number'),
            TraceAttributes::LOCKER_UUID => self::lockerUuidFromTopic($topic),
        ];

        foreach ($scalars as $key => $value) {
            if (is_scalar($value)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Device topics are `locker/{uuid}/...`, but the `locker/` prefix is also used
     * by registration and provisioning-reply topics, whose second segment is a
     * literal word or a client id. Only an actual UUID is reported as one.
     */
    private static function lockerUuidFromTopic(string $topic): ?string
    {
        $segments = explode('/', $topic);

        if ($segments[0] !== 'locker') {
            return null;
        }

        $uuid = $segments[1] ?? null;

        return is_string($uuid) && Str::isUuid($uuid) ? $uuid : null;
    }
}
