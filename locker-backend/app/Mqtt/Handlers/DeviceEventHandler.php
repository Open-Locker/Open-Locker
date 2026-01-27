<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\StorableEvents\DeviceEventReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceEventHandler
{
    /**
     * Handle incoming spontaneous event on topic pattern 'locker/{uuid}/event'.
     *
     * @param  array<string,mixed>  $payload
     */
    public function handle(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/event');

        $eventName = isset($payload['event']) && is_string($payload['event'])
            ? trim($payload['event'])
            : '';

        if ($eventName === '') {
            Log::warning('Device event missing event name; ignoring.', [
                'topic' => $topic,
                'payload' => $payload,
            ]);

            return;
        }

        $eventId = isset($payload['event_id']) && is_string($payload['event_id']) ? $payload['event_id'] : null;
        $timestamp = isset($payload['timestamp']) && is_string($payload['timestamp']) ? $payload['timestamp'] : null;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        Log::info('Device event received.', [
            'uuid' => $lockerBankUuid,
            'event' => $eventName,
            'event_id' => $eventId,
        ]);

        event(new DeviceEventReceived(
            lockerBankUuid: $lockerBankUuid,
            event: $eventName,
            eventId: $eventId,
            timestamp: $timestamp,
            data: $data,
        ));
    }
}
