<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\Services\MqttUserService;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerProvisioningReplyFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class MqttReactor extends Reactor implements ShouldQueue
{
    public function __construct(
        private readonly OpenCompartmentCommandPublisher $openCompartmentCommandPublisher,
        private readonly ApplyConfigCommandPublisher $applyConfigCommandPublisher,
        private readonly ProvisioningReplyPublisher $provisioningReplyPublisher,
        private readonly MqttUserService $mqttUserService,
    ) {}

    /**
     * Ensure queued reactor handlers run on the same queue as Spatie's stored event jobs.
     *
     * This avoids situations where the event worker is running, but the default queue
     * worker is not, causing side-effects (like MQTT publishing) to never execute.
     */
    public string $queue = 'events';

    public function onCompartmentOpeningRequested(CompartmentOpeningRequested $event): void
    {
        $this->openCompartmentCommandPublisher->publish($event);
    }

    public function onLockerConfigApplyRequested(LockerConfigApplyRequested $event): void
    {
        $this->applyConfigCommandPublisher->publish($event);
    }

    public function onLockerWasProvisioned(LockerWasProvisioned $event): void
    {
        Log::info('[MqttReactor] Handling LockerWasProvisioned event.', ['uuid' => $event->lockerBankUuid]);

        $mqttUser = $event->lockerBankUuid;
        $mqttPassword = Str::random(32);

        try {
            Log::info('[MqttReactor] Attempting to create MQTT user...');
            $this->mqttUserService->createUser($mqttUser, $mqttPassword, $event->lockerBankUuid);
            Log::info('[MqttReactor] MQTT user created successfully.');

            $this->provisioningReplyPublisher->publishSuccess($event, $mqttUser, $mqttPassword);
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
        $this->provisioningReplyPublisher->publishFailure($event);
    }
}
