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
        $recipients = $this->recipients();
        if ($recipients->isEmpty()) {
            Log::warning('Help requested but no operator holds compartment.open.', [
                'helpRequestUuid' => $event->helpRequestUuid,
                'compartmentUuid' => $event->compartmentUuid,
            ]);

            return;
        }

        $compartment = Compartment::with('lockerBank')->find($event->compartmentUuid);
        $user = User::query()->find($event->actorUserId);

        $compartmentNumber = (int) ($compartment->number ?? 0);
        $lockerBankName = $compartment?->lockerBank->name ?? $event->compartmentUuid;
        $userName = $user?->fullName() ?? "User #{$event->actorUserId}";

        FilamentNotification::make()
            ->warning()
            ->icon('heroicon-o-lifebuoy')
            ->title(__('Help requested'))
            ->body(__(':user needs help with compartment :number on :bank: ":message"', [
                'user' => $userName,
                'number' => $compartmentNumber,
                'bank' => $lockerBankName,
                'message' => $event->message,
            ]))
            ->broadcast($recipients);

        Notification::send($recipients, new CompartmentHelpRequestedNotification(
            userName: $userName,
            userEmail: (string) ($user->email ?? ''),
            lockerBankName: $lockerBankName,
            compartmentNumber: $compartmentNumber,
            message: $event->message,
        ));
    }

    /**
     * The same operators CompartmentOpenDeviationAlertReactor alerts.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $operatorIds = UserRole::query()
            ->whereIn('role', Role::valuesWithPermission(Permission::CompartmentOpen))
            ->pluck('user_id');

        return User::query()->whereIn('id', $operatorIds)->get();
    }
}
