<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared "edit content note" action for every compartment surface in the panel.
 *
 * Managers hold compartment.access.manage and so may edit notes, but the only
 * place the action existed was the locker bank's compartment table, which needs
 * lockerbank.configure to reach. A manager therefore had to grant themselves
 * access to a compartment and use the mobile app to leave a note.
 *
 * Kept here rather than copied per table for the same reason as
 * OpenCompartmentAction: the authorisation check, the character cap and the
 * notifications have to stay identical wherever a note is edited.
 */
final class EditContentNoteAction
{
    public static function make(): Action
    {
        return Action::make('editContentNote')
            ->label(__('Edit note'))
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(fn (Compartment $record): string => __('Edit note — compartment #:number', ['number' => $record->number]))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('Save note'))
            // The same permission CompartmentService enforces, so the button is
            // never offered to someone who would be refused.
            ->visible(fn (): bool => Filament::auth()->user()?->can(Permission::CompartmentAccessManage->value) ?? false)
            ->fillForm(fn (Compartment $record): array => ['note' => $record->content_note])
            ->form([
                Forms\Components\Textarea::make('note')
                    ->label(__('Note'))
                    ->rows(3)
                    // Matches the column width and the API rule.
                    ->maxLength(80)
                    ->helperText(__('Leave empty to clear the note.')),
            ])
            ->action(function (Compartment $record, array $data): void {
                $user = Filament::auth()->user();
                if (! $user instanceof User) {
                    Notification::make()
                        ->title(__('Unable to save note'))
                        ->body(__('Your session has expired. Please log in again.'))
                        ->danger()
                        ->send();

                    return;
                }

                // Blank clears the note, same as the mobile endpoint.
                $note = trim((string) ($data['note'] ?? ''));

                try {
                    app(CompartmentService::class)->updateContentNote(
                        actor: $user,
                        compartment: $record,
                        note: $note === '' ? null : $note,
                    );
                } catch (AuthorizationException) {
                    Notification::make()
                        ->title(__('Not allowed to edit this note'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($note === '' ? __('Note cleared') : __('Note updated'))
                    ->success()
                    ->send();
            });
    }
}
