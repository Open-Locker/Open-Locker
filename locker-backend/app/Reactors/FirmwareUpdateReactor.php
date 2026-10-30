<?php

declare(strict_types=1);

namespace App\Reactors;

use App\StorableEvents\DeviceEventReceived;
use App\StorableEvents\FirmwareUpdateFailed;
use App\StorableEvents\FirmwareUpdateRequested;
use App\Support\EventSourcing\StoredEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Derives the failure of an accepted update from the `firmware_update_failed`
 * device event (ADR-0076). The command's one response is handled by
 * {@see CommandResponseReactor}.
 *
 * Idempotent per transaction, with the same single-worker constraint as
 * {@see DoorDetectionReactor}: the guard is a read followed by a write.
 */
class FirmwareUpdateReactor extends Reactor implements ShouldQueue
{
    public function __construct(private readonly StoredEventDispatcher $storedEventDispatcher) {}

    public string $queue = 'events';

    public const UPDATE_FAILED = 'firmware_update_failed';

    public function onDeviceEventReceived(DeviceEventReceived $event): void
    {
        if ($event->event !== self::UPDATE_FAILED) {
            return;
        }

        $transactionId = $event->data['transaction_id'] ?? null;
        $errorCode = $event->data['error_code'] ?? null;

        if (! is_string($transactionId) || trim($transactionId) === '') {
            Log::warning('Firmware update failure event without a transaction id.', [
                'lockerBankUuid' => $event->lockerBankUuid,
            ]);

            return;
        }
        $transactionId = trim($transactionId);

        // Only a failure of an update this backend asked this bank for is history.
        $requested = EloquentStoredEvent::query()
            ->where('event_class', FirmwareUpdateRequested::class)
            ->where('event_properties->commandId', $transactionId)
            ->where('event_properties->lockerBankUuid', $event->lockerBankUuid)
            ->exists();

        if (! $requested) {
            Log::warning('No FirmwareUpdateRequested found for firmware update failure event.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'transactionId' => $transactionId,
            ]);

            return;
        }

        $alreadyFailed = EloquentStoredEvent::query()
            ->where('event_class', FirmwareUpdateFailed::class)
            ->where('event_properties->transactionId', $transactionId)
            ->exists();

        if ($alreadyFailed) {
            return;
        }

        $this->storedEventDispatcher->dispatch(new FirmwareUpdateFailed(
            lockerBankUuid: $event->lockerBankUuid,
            transactionId: $transactionId,
            errorCode: is_string($errorCode) ? $errorCode : null,
            timestamp: $event->timestamp,
        ));
    }
}
