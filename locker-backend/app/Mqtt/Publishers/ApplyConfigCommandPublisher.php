<?php

declare(strict_types=1);

namespace App\Mqtt\Publishers;

use App\Mqtt\MqttPublisher;
use App\StorableEvents\LockerConfigApplyRequested;
use Illuminate\Support\Facades\Log;

class ApplyConfigCommandPublisher
{
    public function __construct(
        private readonly MqttPublisher $mqttPublisher,
    ) {}

    public function publish(LockerConfigApplyRequested $event): void
    {
        $topic = "locker/{$event->lockerBankUuid}/command";

        Log::info('[ApplyConfigCommandPublisher] Publishing apply_config command.', [
            'topic' => $topic,
            'lockerBankUuid' => $event->lockerBankUuid,
            'transactionId' => $event->commandId,
            'configHash' => $event->configHash,
            'heartbeatIntervalSeconds' => $event->heartbeatIntervalSeconds,
            'compartmentCount' => count($event->compartments),
        ]);

        $this->mqttPublisher->publish($topic, [
            'action' => 'apply_config',
            'transaction_id' => $event->commandId,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'config_hash' => $event->configHash,
                'heartbeat_interval_seconds' => $event->heartbeatIntervalSeconds,
                'compartments' => $event->compartments,
            ],
        ]);
    }
}
