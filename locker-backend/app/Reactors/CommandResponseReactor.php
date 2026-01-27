<?php

declare(strict_types=1);

namespace App\Reactors;

use App\StorableEvents\CommandResponseReceived;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigAckFailed;
use App\StorableEvents\LockerConfigAcknowledged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class CommandResponseReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    /**
     * Derive domain-specific events from the generic CommandResponseReceived event.
     *
     * This method is idempotent: it checks if a derived event for the same transaction
     * already exists before emitting it.
     */
    public function onCommandResponseReceived(CommandResponseReceived $event): void
    {
        $action = (string) ($event->action ?? '');
        $result = (string) ($event->result ?? '');

        if ($action === 'apply_config' && $result === 'success') {
            $appliedHash = $event->data['applied_config_hash'] ?? null;
            if (! is_string($appliedHash) || strlen($appliedHash) !== 64) {
                Log::warning('apply_config success missing valid applied_config_hash', [
                    'lockerBankUuid' => $event->lockerBankUuid,
                    'transactionId' => $event->transactionId,
                ]);

                return;
            }

            if ($this->derivedEventExists(LockerConfigAcknowledged::class, $event->transactionId)) {
                return;
            }

            event(new LockerConfigAcknowledged(
                lockerBankUuid: $event->lockerBankUuid,
                transactionId: $event->transactionId,
                appliedConfigHash: $appliedHash,
                timestamp: $event->timestamp,
            ));

            return;
        }

        if ($action === 'apply_config' && $result === 'error') {
            if ($this->derivedEventExists(LockerConfigAckFailed::class, $event->transactionId)) {
                return;
            }

            event(new LockerConfigAckFailed(
                lockerBankUuid: $event->lockerBankUuid,
                transactionId: $event->transactionId,
                errorCode: $event->errorCode,
                message: $event->message,
                timestamp: $event->timestamp,
            ));

            return;
        }

        if ($action === 'open_compartment' && ($result === 'success' || $result === 'error')) {
            $request = EloquentStoredEvent::query()
                ->where('event_class', CompartmentOpeningRequested::class)
                ->where('event_properties->commandId', $event->transactionId)
                ->first();

            if (! $request) {
                Log::warning('No CompartmentOpeningRequested found for command response; cannot derive compartment event.', [
                    'lockerBankUuid' => $event->lockerBankUuid,
                    'transactionId' => $event->transactionId,
                ]);

                return;
            }

            /** @var array<string,mixed> $props */
            $props = $request->event_properties;
            $compartmentUuid = (string) ($props['compartmentUuid'] ?? '');
            $compartmentNumber = (int) ($props['compartmentNumber'] ?? 0);

            if ($compartmentUuid === '' || $compartmentNumber <= 0) {
                Log::warning('Invalid CompartmentOpeningRequested properties; cannot derive compartment event.', [
                    'storedEventId' => $request->id,
                    'transactionId' => $event->transactionId,
                ]);

                return;
            }

            if ($result === 'success') {
                if ($this->derivedEventExists(CompartmentOpened::class, $event->transactionId)) {
                    return;
                }

                event(new CompartmentOpened(
                    lockerBankUuid: $event->lockerBankUuid,
                    compartmentUuid: $compartmentUuid,
                    compartmentNumber: $compartmentNumber,
                    transactionId: $event->transactionId,
                    timestamp: $event->timestamp,
                ));

                return;
            }

            if ($this->derivedEventExists(CompartmentOpeningFailed::class, $event->transactionId)) {
                return;
            }

            event(new CompartmentOpeningFailed(
                lockerBankUuid: $event->lockerBankUuid,
                compartmentUuid: $compartmentUuid,
                compartmentNumber: $compartmentNumber,
                transactionId: $event->transactionId,
                errorCode: $event->errorCode,
                message: $event->message,
                timestamp: $event->timestamp,
            ));
        }
    }

    private function derivedEventExists(string $eventClass, string $transactionId): bool
    {
        return EloquentStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where('event_properties->transactionId', $transactionId)
            ->exists();
    }
}
