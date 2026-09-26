<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\CompartmentHelpRequestedNotification;
use App\StorableEvents\CompartmentHelpRequested;
use App\Support\EventSourcing\OrganizationStamp;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Passes a user's help request to the operators the same way a jammed door
 * reaches them (CompartmentOpenDeviationAlertReactor): a live toast in the
 * panel plus an email they can reply to.
 */
class CompartmentHelpRequestAlertReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onCompartmentHelpRequested(CompartmentHelpRequested $event): void
    {
        // Queued, so no organization is in context: the operators are the ones
        // of the organization the request was made in, taken from the event.
        $recipients = $this->recipients(OrganizationStamp::from($event));
        if ($recipients->isEmpty()) {
            Log::warning('Help requested but no operator holds compartment.open.', [
                'helpRequestUuid' => $event->helpRequestUuid,
                'compartmentUuid' => $event->compartmentUuid,
            ]);

            return;
        }

        // Looked up by a globally unique uuid from the event; scoped, a queued
        // job would find nothing and the email would name no compartment.
        $compartment = Compartment::withoutGlobalScope('organization')
            ->with(['lockerBank' => fn ($query) => $query->withoutGlobalScope('organization')])
            ->find($event->compartmentUuid);
        $user = User::query()->find($event->actorUserId);

        $compartmentNumber = (int) ($compartment->number ?? 0);
        $lockerBankName = $compartment?->lockerBank->name ?? $event->compartmentUuid;
        $userName = $user?->fullName() ?? "User #{$event->actorUserId}";

        $toastBody = __(':user needs help with compartment :number on :bank: ":message"', [
            'user' => $userName,
            'number' => $compartmentNumber,
            'bank' => $lockerBankName,
            'message' => $event->message,
        ]);
        if ($event->callbackPhone !== null) {
            $toastBody .= ' '.__('Phone for a call back: :phone', ['phone' => $event->callbackPhone]);
        }

        FilamentNotification::make()
            ->warning()
            ->icon('heroicon-o-lifebuoy')
            ->title(__('Help requested'))
            ->body($toastBody)
            ->broadcast($recipients);

        Notification::send($recipients, new CompartmentHelpRequestedNotification(
            userName: $userName,
            userEmail: $user?->email,
            lockerBankName: $lockerBankName,
            compartmentNumber: $compartmentNumber,
            message: $event->message,
            callbackPhone: $event->callbackPhone,
        ));
    }

    /**
     * The same operators CompartmentOpenDeviationAlertReactor alerts: those of
     * one organization, never every organization's.
     *
     * @return Collection<int, User>
     */
    private function recipients(?string $organizationId): Collection
    {
        $operatorIds = UserRole::query()
            ->whereIn('role', Role::valuesWithPermission(Permission::CompartmentOpen))
            ->where('organization_id', $organizationId)
            ->pluck('user_id');

        return User::query()->whereIn('id', $operatorIds)->get();
    }
}
