<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Models\Compartment;
use App\Models\LockerBank;
use App\StorableEvents\CompartmentDoorAlreadyOpen;
use App\StorableEvents\CompartmentDoorOpenDetected;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\CompartmentOpenNotDetected;
use App\StorableEvents\CompartmentUncommandedOpenDetected;
use App\StorableEvents\DeviceEventReceived;
use App\Support\EventSourcing\StoredEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Derives door-open domain events from the generic device event stream.
 *
 * The client reports the physical door separately from the command response, so
 * this is where "the door actually opened" enters the domain. Idempotent: a
 * redelivered device event does not produce a second derived event.
 *
 * That idempotence holds only while one worker consumes this queue. The guard
 * is a read followed by a write, not an atomic operation, so two consumers
 * handling the same redelivery would both find nothing recorded and both emit.
 * Duplicated events here are permanent history — every replay would count the
 * open twice — so the `events` queue must stay single-worker until the guard
 * takes a lock or the store enforces uniqueness.
 */
class DoorDetectionReactor extends Reactor implements ShouldQueue
{
    public function __construct(private readonly StoredEventDispatcher $storedEventDispatcher) {}

    public string $queue = 'events';

    public const OPEN_DETECTED = 'compartment_open_detected';

    public const OPEN_FAILED = 'compartment_open_failed';

    public const UNCOMMANDED_OPEN = 'compartment_uncommanded_open';

    public function onDeviceEventReceived(DeviceEventReceived $event): void
    {
        match ($event->event) {
            self::OPEN_DETECTED => $this->handleOpenDetected($event),
            self::OPEN_FAILED => $this->handleOpenFailed($event),
            self::UNCOMMANDED_OPEN => $this->handleUncommandedOpen($event),
            default => null,
        };
    }

    private function handleOpenDetected(DeviceEventReceived $event): void
    {
        $transactionId = $this->transactionId($event);
        if ($transactionId === null) {
            return;
        }

        $request = $this->resolveOpeningRequest($event, $transactionId);
        if ($request === null) {
            return;
        }

        $outcome = is_string($event->data['outcome'] ?? null) ? $event->data['outcome'] : 'opened';

        if ($outcome === 'already_open') {
            if ($this->derivedEventExists(CompartmentDoorAlreadyOpen::class, $transactionId)) {
                return;
            }

            $this->storedEventDispatcher->dispatch(new CompartmentDoorAlreadyOpen(
                lockerBankUuid: $event->lockerBankUuid,
                compartmentUuid: $request['compartmentUuid'],
                compartmentNumber: $request['compartmentNumber'],
                transactionId: $transactionId,
                timestamp: $event->timestamp,
            ));

            return;
        }

        if ($this->derivedEventExists(CompartmentDoorOpenDetected::class, $transactionId)) {
            return;
        }

        $detectionMs = $event->data['detection_ms'] ?? null;

        $this->storedEventDispatcher->dispatch(new CompartmentDoorOpenDetected(
            lockerBankUuid: $event->lockerBankUuid,
            compartmentUuid: $request['compartmentUuid'],
            compartmentNumber: $request['compartmentNumber'],
            transactionId: $transactionId,
            detectionMs: is_int($detectionMs) ? $detectionMs : null,
            timestamp: $event->timestamp,
        ));
    }

    private function handleOpenFailed(DeviceEventReceived $event): void
    {
        $transactionId = $this->transactionId($event);
        if ($transactionId === null) {
            return;
        }

        $request = $this->resolveOpeningRequest($event, $transactionId);
        if ($request === null) {
            return;
        }

        if ($this->derivedEventExists(CompartmentOpenNotDetected::class, $transactionId)) {
            return;
        }

        $errorCode = $event->data['error_code'] ?? null;

        $this->storedEventDispatcher->dispatch(new CompartmentOpenNotDetected(
            lockerBankUuid: $event->lockerBankUuid,
            compartmentUuid: $request['compartmentUuid'],
            compartmentNumber: $request['compartmentNumber'],
            transactionId: $transactionId,
            errorCode: is_string($errorCode) ? $errorCode : null,
            timestamp: $event->timestamp,
        ));
    }

    /**
     * No transaction to correlate against by definition: this is a door that
     * opened without a command, so the compartment is resolved from the bank.
     */
    private function handleUncommandedOpen(DeviceEventReceived $event): void
    {
        $compartmentNumber = $event->data['compartment_number'] ?? null;
        if (! is_int($compartmentNumber) || $compartmentNumber <= 0) {
            Log::warning('Uncommanded open event without a usable compartment number.', [
                'lockerBankUuid' => $event->lockerBankUuid,
            ]);

            return;
        }

        $lockerBank = LockerBank::query()->find($event->lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('Uncommanded open event for unknown locker bank.', [
                'lockerBankUuid' => $event->lockerBankUuid,
            ]);

            return;
        }

        $compartment = Compartment::query()
            ->where('locker_bank_id', $lockerBank->id)
            ->where('number', $compartmentNumber)
            ->first();

        if (! $compartment) {
            Log::warning('Uncommanded open event for unknown compartment.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'compartmentNumber' => $compartmentNumber,
            ]);

            return;
        }

        $sinceFire = $event->data['milliseconds_since_last_relay_fire'] ?? null;

        $this->storedEventDispatcher->dispatch(new CompartmentUncommandedOpenDetected(
            lockerBankUuid: $event->lockerBankUuid,
            compartmentUuid: (string) $compartment->id,
            compartmentNumber: $compartmentNumber,
            millisecondsSinceLastRelayFire: is_int($sinceFire) ? $sinceFire : null,
            timestamp: $event->timestamp,
        ));
    }

    private function transactionId(DeviceEventReceived $event): ?string
    {
        $transactionId = $event->data['transaction_id'] ?? null;

        if (! is_string($transactionId) || trim($transactionId) === '') {
            Log::warning('Door detection event without a transaction id.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'event' => $event->event,
            ]);

            return null;
        }

        return trim($transactionId);
    }

    /**
     * @return array{compartmentUuid: string, compartmentNumber: int}|null
     */
    private function resolveOpeningRequest(DeviceEventReceived $event, string $transactionId): ?array
    {
        $request = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpeningRequested::class)
            ->where('event_properties->commandId', $transactionId)
            ->first();

        if (! $request) {
            Log::warning('No CompartmentOpeningRequested found for door detection event.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'event' => $event->event,
                'transactionId' => $transactionId,
            ]);

            return null;
        }

        /** @var array<string,mixed> $props */
        $props = $request->event_properties;
        $compartmentUuid = (string) ($props['compartmentUuid'] ?? '');
        $compartmentNumber = (int) ($props['compartmentNumber'] ?? 0);

        if ($compartmentUuid === '' || $compartmentNumber <= 0) {
            Log::warning('Invalid CompartmentOpeningRequested properties for door detection event.', [
                'storedEventId' => $request->id,
                'transactionId' => $transactionId,
            ]);

            return null;
        }

        return ['compartmentUuid' => $compartmentUuid, 'compartmentNumber' => $compartmentNumber];
    }

    private function derivedEventExists(string $eventClass, string $transactionId): bool
    {
        return EloquentStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where('event_properties->transactionId', $transactionId)
            ->exists();
    }
}
