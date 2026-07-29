<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\CompartmentDoorState as DoorState;
use App\Enums\CompartmentOpenRequestStatus;
use App\Models\Compartment;
use Filament\Tables\Columns\TextColumn;

/**
 * Shared door-state presentation, so the compartment list and the locker bank's
 * compartment table say the same thing about the same door.
 *
 * The two drifted apart once already: the door badge and its fault markers only
 * existed on the compartment list, while the locker bank page showed neither.
 */
final class CompartmentDoorStateColumn
{
    /**
     * A jammed compartment reads `closed` exactly like a healthy one, so the
     * fault has to ride on the door badge itself.
     *
     * Only a jam counts. `already_open` says the door was open when someone
     * asked for it — a fact about one past command, not a malfunction. Nothing
     * a working door does afterwards creates a newer open request, so flagging
     * it would leave the compartment marked faulty forever.
     */
    public static function lastOpenFailed(Compartment $compartment): bool
    {
        return $compartment->latestOpenRequest?->status === CompartmentOpenRequestStatus::DoorJammed;
    }

    public static function lastOpenFaultTooltip(Compartment $compartment): ?string
    {
        return self::lastOpenFailed($compartment)
            ? __('The last unlock pulse was sent but the door never opened. The lock may be jammed or blocked, or the door sensor may have failed.')
            : null;
    }

    /**
     * An open door cannot be opened again: the command would be a no-op the
     * device answers with `already_open`.
     */
    public static function canBeOpened(Compartment $compartment): bool
    {
        return in_array($compartment->door_state, [DoorState::Closed, DoorState::Unknown], true);
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('door_state')
            ->label(__('Door'))
            ->badge()
            ->formatStateUsing(fn (DoorState $state): string => $state->label())
            ->color(fn (DoorState $state, Compartment $record): string => match (true) {
                self::lastOpenFailed($record) => 'danger',
                $state === DoorState::Open => 'warning',
                $state === DoorState::Closed => 'success',
                default => 'gray',
            })
            ->icon(fn (Compartment $record): ?string => self::lastOpenFailed($record)
                ? 'heroicon-m-exclamation-triangle'
                : null)
            ->tooltip(fn (Compartment $record): ?string => self::lastOpenFaultTooltip($record))
            ->placeholder(__('unknown'));
    }
}
