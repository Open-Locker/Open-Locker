<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Services\MqttUserService;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerProvisioningReplyFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpMqtt\Client\Facades\MQTT;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class MqttReactor extends Reactor implements ShouldQueue
{
    /**
     * Ensure queued reactor handlers run on the same queue as Spatie's stored event jobs.
     *
     * This avoids situations where the event worker is running, but the default queue
     * worker is not, causing side-effects (like MQTT publishing) to never execute.
     */
    public string $queue = 'events';

    public function onCompartmentOpeningRequested(CompartmentOpeningRequested $event): void
    {
        $topic = "locker/{$event->lockerBankUuid}/command";

        $payload = json_encode([
            // Keep payload aligned with docs/mqtt_integration_plan.md
            // (commands contain "action" + "transaction_id")
            'action' => 'open_compartment',
            'transaction_id' => $event->commandId,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'compartment_id' => $event->compartmentUuid,
                'compartment_number' => $event->compartmentNumber,
            ],
        ]);

        Log::info('[MqttReactor] Publishing open_compartment command.', [
            'topic' => $topic,
            'lockerBankUuid' => $event->lockerBankUuid,
            'compartmentUuid' => $event->compartmentUuid,
            'compartmentNumber' => $event->compartmentNumber,
            'transactionId' => $event->commandId,
        ]);

        MQTT::connection('publisher')->publish($topic, (string) $payload, 1);
    }

    public function onLockerConfigApplyRequested(LockerConfigApplyRequested $event): void
    {
        $topic = "locker/{$event->lockerBankUuid}/command";

        $payload = json_encode([
            'action' => 'apply_config',
            'transaction_id' => $event->commandId,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'config_hash' => $event->configHash,
                'heartbeat_interval_seconds' => $event->heartbeatIntervalSeconds,
                'compartments' => $event->compartments,
            ],
        ]);

        Log::info('[MqttReactor] Publishing apply_config command.', [
            'topic' => $topic,
            'lockerBankUuid' => $event->lockerBankUuid,
            'transactionId' => $event->commandId,
            'configHash' => $event->configHash,
            'heartbeatIntervalSeconds' => $event->heartbeatIntervalSeconds,
            'compartmentCount' => count($event->compartments),
        ]);

        MQTT::connection('publisher')->publish($topic, (string) $payload, 1);
    }

    public function onLockerWasProvisioned(LockerWasProvisioned $event): void
    {
        Log::info('[MqttReactor] Handling LockerWasProvisioned event.', ['uuid' => $event->lockerBankUuid]);

        $mqttUser = $event->lockerBankUuid;
        $mqttPassword = Str::random(32);

        try {
            Log::info('[MqttReactor] Attempting to create MQTT user...');
            app(MqttUserService::class)->createUser($mqttUser, $mqttPassword, $event->lockerBankUuid);
            Log::info('[MqttReactor] MQTT user created successfully.');

            $payload = json_encode([
                'status' => 'success',
                'data' => [
                    'mqtt_user' => $mqttUser,
                    'mqtt_password' => $mqttPassword,
                ],
            ]);

            Log::info("[MqttReactor] Attempting to publish credentials to topic: {$event->replyToTopic}");
            MQTT::connection('publisher')->publish($event->replyToTopic, $payload, 1);
            Log::info('[MqttReactor] Published credentials successfully.');

        } catch (\Exception $e) {
            Log::error('[MqttReactor] Failed to provision MQTT user or send credentials.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'exception' => $e->getMessage(),
            ]);

            // Record a failure event so we have a durable audit trail
            event(new LockerProvisioningReplyFailed(
                lockerBankUuid: $event->lockerBankUuid,
                replyToTopic: $event->replyToTopic,
                reason: $e->getMessage(),
            ));

            // Rethrow to trigger queue retry strategy
            throw $e;
        }
    }

    public function onLockerProvisioningFailed(LockerProvisioningFailed $event): void
    {
        Log::info('[MqttReactor] Handling LockerProvisioningFailed event.');
        $payload = json_encode([
            'status' => 'error',
            'message' => $event->reason,
        ]);

        Log::info("[MqttReactor] Publishing failure to topic: {$event->replyToTopic}");
        MQTT::connection('publisher')->publish($event->replyToTopic, $payload, 1);
    }
}
