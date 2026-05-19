<?php

declare(strict_types=1);

namespace App\Mqtt\Publishers;

use App\Mqtt\MqttPublisher;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Support\Facades\Log;

class ProvisioningReplyPublisher
{
    public function __construct(
        private readonly MqttPublisher $mqttPublisher,
    ) {}

    public function publishSuccess(
        LockerWasProvisioned $event,
        string $mqttUser,
        string $mqttPassword,
    ): void {
        Log::info('[ProvisioningReplyPublisher] Publishing provisioning success reply.', [
            'topic' => $event->replyToTopic,
            'lockerBankUuid' => $event->lockerBankUuid,
        ]);

        $this->mqttPublisher->publish($event->replyToTopic, [
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'mqtt_user' => $mqttUser,
                'mqtt_password' => $mqttPassword,
            ],
        ]);
    }

    public function publishFailure(LockerProvisioningFailed $event): void
    {
        Log::info('[ProvisioningReplyPublisher] Publishing provisioning failure reply.', [
            'topic' => $event->replyToTopic,
            'reason' => $event->reason,
        ]);

        $this->mqttPublisher->publish($event->replyToTopic, [
            'status' => 'error',
            'timestamp' => now()->toIso8601String(),
            'message' => $event->reason,
        ]);
    }

    public function publishInvalidToken(string $replyToTopic): void
    {
        Log::info('[ProvisioningReplyPublisher] Publishing invalid token failure reply.', [
            'topic' => $replyToTopic,
        ]);

        $this->mqttPublisher->publish($replyToTopic, [
            'status' => 'error',
            'timestamp' => now()->toIso8601String(),
            'message' => 'Invalid or expired provisioning token.',
        ]);
    }
}
