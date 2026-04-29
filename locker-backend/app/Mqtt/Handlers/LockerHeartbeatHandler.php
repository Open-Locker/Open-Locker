<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Models\LockerBank;
use App\Mqtt\InboundMqttProtocolGuard;
use App\StorableEvents\LockerConnectionRestored;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles AsyncAPI heartbeat payloads on locker/{uuid}/state/heartbeat (retain=false).
 */
class LockerHeartbeatHandler extends AbstractInboundMqttHandler
{
    public function __construct(InboundMqttProtocolGuard $guard)
    {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/state/heartbeat';
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT heartbeat received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'message_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'uptime_seconds' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = $this->lockerBankUuidFromTopic($topic);

        $timestamp = isset($payload['timestamp']) && is_string($payload['timestamp'])
            ? $payload['timestamp']
            : null;

        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('Heartbeat received for unknown locker bank', [
                'uuid' => $lockerBankUuid,
                'timestamp' => $timestamp,
            ]);

            return;
        }

        $ts = $timestamp ? Carbon::parse($timestamp) : now();
        $wasOffline = $lockerBank->connection_status === 'offline';

        $lockerBank->forceFill([
            'last_heartbeat_at' => $ts,
        ])->save();

        Log::info('Heartbeat received', [
            'uuid' => $lockerBankUuid,
            'timestamp' => $timestamp,
        ]);

        if ($wasOffline) {
            event(new LockerConnectionRestored(
                lockerBankUuid: $lockerBankUuid,
                restoredAtIso8601: $ts->toIso8601String(),
                reason: 'heartbeat',
            ));
        }
    }

    private function lockerBankUuidFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);

        return $parts[1] ?? '';
    }
}
