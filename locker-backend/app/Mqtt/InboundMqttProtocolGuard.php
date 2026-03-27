<?php

declare(strict_types=1);

namespace App\Mqtt;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InboundMqttProtocolGuard
{
    private const MESSAGE_ID_TTL_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function allow(string $topic, array $payload, bool $requiresTransactionId = false): bool
    {
        $messageId = Arr::get($payload, 'message_id');

        if (! is_string($messageId) || trim($messageId) === '') {
            Log::warning('Rejected inbound MQTT payload without message_id.', [
                'topic' => $topic,
                'payload' => $payload,
                'requires_transaction_id' => $requiresTransactionId,
            ]);

            return false;
        }

        if ($requiresTransactionId) {
            $transactionId = Arr::get($payload, 'transaction_id');

            if (! is_string($transactionId) || trim($transactionId) === '') {
                Log::warning('Rejected inbound MQTT payload without transaction_id.', [
                    'topic' => $topic,
                    'message_id' => $messageId,
                    'payload' => $payload,
                ]);

                return false;
            }
        }

        $cacheKey = sprintf('mqtt:inbound:message-id:%s', $messageId);
        $isFirst = Cache::add($cacheKey, now()->toIso8601String(), self::MESSAGE_ID_TTL_SECONDS);

        if (! $isFirst) {
            Log::info('Duplicate inbound MQTT payload ignored.', [
                'topic' => $topic,
                'message_id' => $messageId,
            ]);

            return false;
        }

        return true;
    }
}
