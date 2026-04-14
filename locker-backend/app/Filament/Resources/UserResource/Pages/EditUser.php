<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('sendPasswordResetLink')
                    ->label('Passwort-Reset senden')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Passwort-Reset-Mail senden')
                    ->modalDescription('Soll eine Passwort-Reset-Mail an diesen Nutzer gesendet werden?')
                    ->modalSubmitActionLabel('Ja, Passwort-Reset senden')
                    ->action(function (User $record): void {
                        $status = $record->sendAdminPasswordResetLink();

                        if ($status === Password::RESET_LINK_SENT) {
                            Notification::make()
                                ->title('Passwort-Reset-Mail gesendet')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Passwort-Reset-Mail konnte nicht gesendet werden')
                            ->body(trans($status))
                            ->danger()
                            ->send();
                    }),
                Actions\Action::make('sendVerificationEmail')
                    ->label('Verifizierungs-Mail senden')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->hidden(fn (User $record): bool => $record->hasVerifiedEmail())
                    ->requiresConfirmation()
                    ->modalHeading('Verifizierungs-Mail senden')
                    ->modalDescription('Soll eine Verifizierungs-Mail an diesen Nutzer gesendet werden?')
                    ->modalSubmitActionLabel('Ja, Verifizierungs-Mail senden')
                    ->action(function (User $record): void {
                        if (! $record->sendAdminVerificationEmail()) {
                            Notification::make()
                                ->title('E-Mail-Adresse ist bereits verifiziert')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Verifizierungs-Mail gesendet')
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('setAsAdmin')
                    ->hidden(fn (User $record): bool => $record->isAdmin())
                    ->label('zum Admin machen')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer zum Admin machen')
                    ->modalDescription('Soll dieser Nutzer Adminrechte erhalten?')
                    ->modalSubmitActionLabel('Ja, gib diesem Nutzer Adminrechte')
                    ->action(function (User $record): void {
                        $record->makeAdmin();

                        Notification::make()
                            ->title('Nutzer ist nun Admin')
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('removeAdmin')
                    ->hidden(fn (User $record): bool => ! $record->isAdmin())
                    ->label('Adminrechte entziehen')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer Adminrechte entziehen')
                    ->modalDescription('Sollen diesem Nutzer Adminrechte entzogen werden?')
                    ->modalSubmitActionLabel('Ja, nimm diesem Nutzer die Adminrechte')
                    ->action(function (User $record): void {
                        $remainingAdmins = User::query()
                            ->whereNotNull('is_admin_since')
                            ->whereKeyNot($record->getKey())
                            ->count();

                        if ($remainingAdmins === 0) {
                            Notification::make()
                                ->title('Aktion abgebrochen')
                                ->body('Dem letzten Admin können nicht die Adminrechte entzogen werden.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->removeAdmin();

                        Notification::make()
                            ->title('Nutzer ist nun nicht mehr Admin')
                            ->success()
                            ->send();
                    }),
            ])
                ->label('Aktionen')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button(),
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, User $record) {
                    if ($record->is_admin_since) {
                        $adminCount = User::whereNotNull('is_admin_since')->count();
                        if ($adminCount <= 1) {
                            Notification::make()
                                ->title('Aktion abgebrochen')
                                ->body('Der letzte Admin kann nicht gelöscht werden.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }
                }),
        ];
    }
}
