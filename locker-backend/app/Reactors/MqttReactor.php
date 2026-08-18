<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Models\LockerBank;
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
use Illuminate\Support\Facades\DB;
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

        if ($event->provisioningGeneration === null) {
            Log::info('[MqttReactor] Ignoring stale LockerWasProvisioned event.', [
                'uuid' => $event->lockerBankUuid,
            ]);

            return;
        }

        $mqttUser = $event->lockerBankUuid;
        $mqttPassword = Str::random(32);

        try {
            $created = DB::transaction(function () use ($event, $mqttPassword, $mqttUser): bool {
                if (! $this->lockCurrentGeneration($event)) {
                    return false;
                }

                Log::info('[MqttReactor] Attempting to create MQTT user...');
                $this->mqttUserService->createUser($mqttUser, $mqttPassword, $event->lockerBankUuid);
                Log::info('[MqttReactor] MQTT user created successfully.');

                return true;
            });

            if (! $created) {
                $this->logStaleProvisioningEvent($event);

                return;
            }

            $published = DB::transaction(function () use ($event, $mqttPassword, $mqttUser): bool {
                if (! $this->lockCurrentGeneration($event)) {
                    return false;
                }

                $this->provisioningReplyPublisher->publishSuccess($event, $mqttUser, $mqttPassword);

                return true;
            });

            if (! $published) {
                $this->logStaleProvisioningEvent($event);
            }
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

    private function lockCurrentGeneration(LockerWasProvisioned $event): bool
    {
        $lockerBank = LockerBank::query()
            ->whereKey($event->lockerBankUuid)
            ->lockForUpdate()
            ->first();

        return $lockerBank !== null
            && $lockerBank->provisioned_at !== null
            && $lockerBank->provisioning_generation !== null
            && $event->provisioningGeneration !== null
            && hash_equals($lockerBank->provisioning_generation, $event->provisioningGeneration);
    }

    private function logStaleProvisioningEvent(LockerWasProvisioned $event): void
    {
        Log::info('[MqttReactor] Ignoring stale LockerWasProvisioned event.', [
            'uuid' => $event->lockerBankUuid,
        ]);
    }

    public function onLockerProvisioningFailed(LockerProvisioningFailed $event): void
    {
        Log::info('[MqttReactor] Handling LockerProvisioningFailed event.');
        $this->provisioningReplyPublisher->publishFailure($event);
    }
}
