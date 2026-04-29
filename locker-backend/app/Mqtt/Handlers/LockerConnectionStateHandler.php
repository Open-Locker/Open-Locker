<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Mqtt\InboundMqttProtocolGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Validates AsyncAPI connection/LWT payloads on locker/{uuid}/state/connection (retain=false).
 *
 * Product semantics for mapping these signals to locker offline state are not finalized.
 * Until then: validate + structured logging only (heartbeat timeout remains authoritative).
 */
class LockerConnectionStateHandler extends AbstractInboundMqttHandler
{
    public function __construct(InboundMqttProtocolGuard $guard)
    {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/state/connection';
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT connection state received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'message_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'status' => ['required', Rule::in(['offline'])],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = $this->lockerBankUuidFromTopic($topic);

        Log::info('MQTT locker connection payload accepted (no domain projection)', [
            'topic' => $topic,
            'locker_bank_uuid' => $lockerBankUuid,
            'message_id' => $payload['message_id'] ?? null,
            'timestamp' => $payload['timestamp'] ?? null,
            'status' => $payload['status'] ?? null,
            'reason' => $payload['reason'] ?? null,
        ]);

        // TODO Implement domain effects
    }

    private function lockerBankUuidFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);

        return $parts[1] ?? '';
    }
}
