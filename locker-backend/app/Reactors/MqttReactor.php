<?php

namespace App\Reactors;

use App\Services\MqttUserService;
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
    public function onLockerWasProvisioned(LockerWasProvisioned $event): void
    {
        Log::info('[MqttReactor] Handling LockerWasProvisioned event.', ['uuid' => $event->lockerBankUuid]);

        $mqttUser = $event->lockerBankUuid;
        $mqttPassword = Str::random(32);

        try {
            Log::info('[MqttReactor] Attempting to create MQTT user...');
            app(MqttUserService::class)->createUser($mqttUser, $mqttPassword);
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
