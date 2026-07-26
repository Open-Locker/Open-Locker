<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Enums\CompartmentOpenDeviation;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\LockerBank;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Security\CompartmentOpenDeviationNotification;
use App\StorableEvents\CompartmentDoorAlreadyOpen;
use App\StorableEvents\CompartmentOpenNotDetected;
use App\StorableEvents\CompartmentUncommandedOpenDetected;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Alerts operators about every way a compartment can misbehave (ADR-0031).
 *
 * All deviations are treated alike: a live danger toast in the panel plus an
 * email. A jam is a maintenance problem and an uncommanded open is a security
 * problem, but both mean someone has to go and look at that locker, so neither
 * is made quieter than the other.
 *
 * The toast is broadcast over Reverb rather than stored, so it reaches whoever is
 * on the panel now; the durable record is the audit log (ADR-0026).
 */
class CompartmentOpenDeviationAlertReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onCompartmentOpenNotDetected(CompartmentOpenNotDetected $event): void
    {
        $this->alert(
            CompartmentOpenDeviation::DoorJammed,
            $event->lockerBankUuid,
            $event->compartmentNumber,
        );
    }

    public function onCompartmentDoorAlreadyOpen(CompartmentDoorAlreadyOpen $event): void
    {
        $this->alert(
            CompartmentOpenDeviation::AlreadyOpen,
            $event->lockerBankUuid,
            $event->compartmentNumber,
        );
    }

    public function onCompartmentUncommandedOpenDetected(CompartmentUncommandedOpenDetected $event): void
    {
        $this->alert(
            CompartmentOpenDeviation::UncommandedOpen,
            $event->lockerBankUuid,
            $event->compartmentNumber,
            $event->millisecondsSinceLastRelayFire,
        );
    }

    private function alert(
        CompartmentOpenDeviation $deviation,
        string $lockerBankUuid,
        int $compartmentNumber,
        ?int $millisecondsSinceLastRelayFire = null,
    ): void {
        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            Log::warning('Compartment deviation detected but no operator holds compartment.open.', [
                'deviation' => $deviation->value,
                'lockerBankUuid' => $lockerBankUuid,
                'compartmentNumber' => $compartmentNumber,
            ]);

            return;
        }

        $lockerBankName = $this->lockerBankName($lockerBankUuid);

        FilamentNotification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->title($deviation->subject())
            ->body($deviation->body($lockerBankName, $compartmentNumber))
            ->broadcast($recipients);

        Notification::send($recipients, new CompartmentOpenDeviationNotification(
            deviation: $deviation,
            lockerBankName: $lockerBankName,
            compartmentNumber: $compartmentNumber,
            millisecondsSinceLastRelayFire: $millisecondsSinceLastRelayFire,
        ));
    }

    /**
     * Mirrors the operational-role lookup in CompartmentStatusBroadcastService,
     * minus the per-compartment access holders: a misbehaving locker is an
     * operator concern, not something to mail everyone who holds access.
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

    private function lockerBankName(string $lockerBankUuid): string
    {
        return LockerBank::query()->find($lockerBankUuid)->name ?? $lockerBankUuid;
    }
}
