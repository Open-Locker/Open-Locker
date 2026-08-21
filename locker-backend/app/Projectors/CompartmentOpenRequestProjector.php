<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Enums\CompartmentOpenRequestStatus;
use App\Models\CompartmentOpenRequest;
use App\StorableEvents\CompartmentDoorAlreadyOpen;
use App\StorableEvents\CompartmentDoorOpenDetected;
use App\StorableEvents\CompartmentOpenAcknowledged;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\CompartmentOpenNotDetected;
use App\StorableEvents\CompartmentOpenRequested;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CompartmentOpenRequestProjector extends Projector
{
    public function onCompartmentOpenRequested(CompartmentOpenRequested $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'status' => CompartmentOpenRequestStatus::Requested,
                'requested_at' => now(),
            ]
        );
    }

    public function onCompartmentOpenAuthorized(CompartmentOpenAuthorized $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'authorization_type' => $event->authorizationType,
                'status' => CompartmentOpenRequestStatus::Accepted,
                'accepted_at' => now(),
                'denied_reason' => null,
            ]
        );
    }

    public function onCompartmentOpenDenied(CompartmentOpenDenied $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'status' => CompartmentOpenRequestStatus::Denied,
                'denied_reason' => $event->reason,
                'denied_at' => now(),
            ]
        );
    }

    public function onCompartmentOpeningRequested(CompartmentOpeningRequested $event): void
    {
        $this->applyToRequest($event->commandId, [
            'status' => CompartmentOpenRequestStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * The unlock pulse was sent. Not a terminal state: door-open detection
     * follows and may still report a jam (ADR-0031).
     *
     * Acknowledgement and the detection outcome are derived by two independent
     * queued reactors, so a fast detection can be projected before the
     * acknowledgement that preceded it. The timestamp is always recorded, but the
     * status only moves forward — a late acknowledgement must never overwrite a
     * physical outcome that has already arrived.
     */
    public function onCompartmentOpenAcknowledged(CompartmentOpenAcknowledged $event): void
    {
        $request = CompartmentOpenRequest::query()->where('command_id', $event->transactionId);

        (clone $request)->update([
            'acknowledged_at' => $this->timestampOrNow($event->timestamp),
        ]);

        (clone $request)
            ->whereIn('status', [
                CompartmentOpenRequestStatus::Requested->value,
                CompartmentOpenRequestStatus::Accepted->value,
                CompartmentOpenRequestStatus::Sent->value,
            ])
            ->update([
                'status' => CompartmentOpenRequestStatus::Acknowledged,
                'error_code' => null,
                'error_message' => null,
            ]);
    }

    public function onCompartmentDoorOpenDetected(CompartmentDoorOpenDetected $event): void
    {
        $this->applyToRequest($event->transactionId, [
            'status' => CompartmentOpenRequestStatus::Opened,
            'opened_at' => $this->timestampOrNow($event->timestamp),
            'open_detection_ms' => $event->detectionMs,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public function onCompartmentDoorAlreadyOpen(CompartmentDoorAlreadyOpen $event): void
    {
        $this->applyToRequest($event->transactionId, [
            'status' => CompartmentOpenRequestStatus::AlreadyOpen,
            'opened_at' => $this->timestampOrNow($event->timestamp),
        ]);
    }

    public function onCompartmentOpenNotDetected(CompartmentOpenNotDetected $event): void
    {
        $this->applyToRequest($event->transactionId, [
            'status' => CompartmentOpenRequestStatus::DoorJammed,
            'failed_at' => $this->timestampOrNow($event->timestamp),
            'error_code' => $event->errorCode,
            'error_message' => __('The unlock pulse was sent but the door did not open.'),
        ]);
    }

    public function onCompartmentOpeningFailed(CompartmentOpeningFailed $event): void
    {
        $this->applyToRequest($event->transactionId, [
            'status' => CompartmentOpenRequestStatus::Failed,
            'failed_at' => $this->timestampOrNow($event->timestamp),
            'error_code' => $event->errorCode,
            'error_message' => $event->message,
        ]);
    }

    /**
     * Replay support only. Emitted before ADR-0031, when a successful command
     * response was recorded as the door having opened; new runs record
     * CompartmentOpenAcknowledged instead.
     */
    public function onCompartmentOpened(CompartmentOpened $event): void
    {
        $this->applyToRequest($event->transactionId, [
            'status' => CompartmentOpenRequestStatus::Opened,
            'opened_at' => $this->timestampOrNow($event->timestamp),
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyToRequest(string $commandId, array $attributes): void
    {
        CompartmentOpenRequest::query()
            ->where('command_id', $commandId)
            ->update($attributes);
    }

    private function timestampOrNow(?string $timestamp): Carbon
    {
        return $timestamp ? Carbon::parse($timestamp) : now();
    }
}
