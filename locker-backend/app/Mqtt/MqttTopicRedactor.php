<?php

declare(strict_types=1);

namespace App\Mqtt;

use Illuminate\Support\Str;

/**
 * Keeps provisioning tokens out of the logs.
 *
 * A device registers on `locker/register/<provisioning token>`, so the topic
 * *is* the credential. Any log line carrying the raw topic leaks it, which is
 * why redaction happens centrally here rather than being remembered at each
 * call site.
 *
 * Nothing of the token survives, not even a prefix: a partial token still
 * shrinks the search space for anyone brute-forcing it.
 */
final class MqttTopicRedactor
{
    private const REGISTRATION_PREFIX = 'locker/register/';

    public const REDACTED = '[redacted]';

    public static function redact(string $topic): string
    {
        if (! Str::startsWith($topic, self::REGISTRATION_PREFIX)) {
            return $topic;
        }

        return self::REGISTRATION_PREFIX.self::REDACTED;
    }
}
