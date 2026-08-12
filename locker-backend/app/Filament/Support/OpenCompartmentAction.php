<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared "open compartment" row action, so the compartment list and the locker
 * bank's compartment table behave identically.
 *
 * The two carried byte-identical copies of this action, which meant every
 * change to the authorisation check, the log context or any of the three
 * notifications had to be made twice to stay in step. Same reasoning as
 * CompartmentDoorStateColumn, which these two tables already share.
 */
final class OpenCompartmentAction
{
    public static function make(): Action
    {
        return Action::make('open')
            ->label(__('Open'))
            ->icon('heroicon-m-bolt')
            ->requiresConfirmation()
            // No permission, no button — and an open door has nothing to open.
            ->visible(fn (Compartment $record): bool => (Filament::auth()->user()?->can(Permission::CompartmentOpen->value) ?? false)
                && CompartmentDoorStateColumn::canBeOpened($record))
            ->action(function (Compartment $record): void {
                try {
                    $user = Filament::auth()->user();

                    if (! $user instanceof User) {
                        Notification::make()
                            ->title(__('Unable to open compartment'))
                            ->body(__('Your session has expired. Please log in again.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $decision = app(CompartmentAccessService::class)->requestOpen($user, $record);

                    if (! $decision['authorized']) {
                        Notification::make()
                            ->title(__('Open command denied'))
                            ->body(__('You are not authorized to open compartment :number of locker :locker.', [
                                'number' => $record->number,
                                'locker' => $record->lockerBank->name ?? __('Unknown'),
                            ]))
                            ->danger()
                            ->send();
                    }
                } catch (Throwable $e) {
                    // The message can carry internal detail, so it goes to the
                    // log and the operator sees a generic failure.
                    Log::error('Failed to request compartment opening from Filament.', [
                        'compartment_id' => $record->id,
                        'locker_bank_id' => $record->locker_bank_id,
                        'number' => $record->number,
                        'error' => $e->getMessage(),
                    ]);

                    Notification::make()
                        ->title(__('Failed to send open command'))
                        ->body(__('Please try again. Details are in the server log.'))
                        ->danger()
                        ->send();
                }
            });
    }
}
