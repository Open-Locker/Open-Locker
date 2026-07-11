<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\Permission;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Filament\Actions;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(UserResource::canView($this->getRecord()), 403);
    }

    protected function getFormActions(): array
    {
        if (! UserResource::canEdit($this->getRecord())) {
            return [
                $this->getCancelFormAction(),
            ];
        }

        return parent::getFormActions();
    }

    protected function beforeSave(): void
    {
        abort_unless(UserResource::canEdit($this->getRecord()), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('sendPasswordResetLink')
                    ->label('Passwort-Reset senden')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                    ->requiresConfirmation()
                    ->modalHeading('Passwort-Reset-Mail senden')
                    ->modalDescription('Soll eine Passwort-Reset-Mail an diesen Nutzer gesendet werden?')
                    ->modalSubmitActionLabel('Ja, Passwort-Reset senden')
                    ->action(function (User $record): void {
                        $status = app(UserAdministrationService::class)->sendPasswordResetLink(
                            actor: self::currentUser(),
                            target: $record,
                        );

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
                    ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                    ->hidden(fn (User $record): bool => $record->hasVerifiedEmail())
                    ->requiresConfirmation()
                    ->modalHeading('Verifizierungs-Mail senden')
                    ->modalDescription('Soll eine Verifizierungs-Mail an diesen Nutzer gesendet werden?')
                    ->modalSubmitActionLabel('Ja, Verifizierungs-Mail senden')
                    ->action(function (User $record): void {
                        if (! app(UserAdministrationService::class)->sendVerificationEmail(self::currentUser(), $record)) {
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
                    ->visible(fn (): bool => self::currentUserCanManageRoles())
                    ->hidden(fn (User $record): bool => $record->isAdmin())
                    ->label('zum Admin machen')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer zum Admin machen')
                    ->modalDescription('Soll dieser Nutzer Adminrechte erhalten?')
                    ->modalSubmitActionLabel('Ja, gib diesem Nutzer Adminrechte')
                    ->action(function (User $record): void {
                        app(UserAdministrationService::class)->makeAdmin(self::currentUser(), $record);

                        Notification::make()
                            ->title('Nutzer ist nun Admin')
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('removeAdmin')
                    ->visible(fn (): bool => self::currentUserCanManageRoles())
                    ->hidden(fn (User $record): bool => ! $record->isAdmin())
                    ->label('Adminrechte entziehen')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Nutzer Adminrechte entziehen')
                    ->modalDescription('Sollen diesem Nutzer Adminrechte entzogen werden?')
                    ->modalSubmitActionLabel('Ja, nimm diesem Nutzer die Adminrechte')
                    ->action(function (User $record): void {
                        if (! app(UserAdministrationService::class)->removeAdmin(self::currentUser(), $record)) {
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
                Actions\Action::make('manageRoles')
                    ->visible(fn (): bool => self::currentUserCanManageRoles())
                    ->label('Rollen verwalten')
                    ->icon('heroicon-o-identification')
                    ->fillForm(fn (User $record): array => [
                        'roles' => array_values(array_intersect($record->roleNames(), self::assignableRoles())),
                    ])
                    ->form([
                        CheckboxList::make('roles')
                            ->label('Rollen')
                            ->options(fn (): array => array_combine(self::assignableRoles(), self::assignableRoles())),
                    ])
                    ->action(function (User $record, array $data): void {
                        app(UserAdministrationService::class)->syncAssignableRoles(
                            actor: self::currentUser(),
                            target: $record,
                            selectedRoles: $data['roles'] ?? [],
                        );

                        Notification::make()
                            ->title('Rollen aktualisiert')
                            ->success()
                            ->send();
                    }),
            ])
                ->label('Aktionen')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button(),
            Actions\DeleteAction::make()
                ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                ->before(function (Actions\DeleteAction $action, User $record) {
                    app(UserAdministrationService::class)->ensureCanManageUser(self::currentUser(), $record);

                    if ($record->isAdmin() && ! User::hasOtherAdmin($record->id)) {
                        Notification::make()
                            ->title('Aktion abgebrochen')
                            ->body('Der letzte Admin kann nicht gelöscht werden.')
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * Catalog roles that can be toggled here. `admin` is handled by its own
     * guarded actions; `user` is the no-role default.
     *
     * @return list<string>
     */
    private static function assignableRoles(): array
    {
        return app(UserAdministrationService::class)->assignableRoleNames();
    }

    private static function currentUserCanManageRoles(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permission::RolesManage->value);
    }

    private static function currentUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
