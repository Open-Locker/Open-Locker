<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use App\StorableEvents\DeviceEventReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceEventHandler extends AbstractInboundMqttHandler
{
    public function __construct(InboundMqttProtocolGuard $guard)
    {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/event';
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT event message received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'event' => ['required', 'string'],
            'event_id' => ['nullable', 'string'],
            'timestamp' => ['nullable', 'string'],
            'data' => ['nullable', 'array'],
        ];
    }

    /**
     * Handle incoming spontaneous event on topic pattern 'locker/{uuid}/event'.
     *
     * @param  array<string,mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = Str::after($topic, 'locker/');
        $lockerBankUuid = Str::before($lockerBankUuid, '/event');
        $eventName = trim((string) $payload['event']);

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
