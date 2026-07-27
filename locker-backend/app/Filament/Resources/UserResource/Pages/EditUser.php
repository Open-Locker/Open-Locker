<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Filament\Actions;
use Filament\Forms\Components\Radio;
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
                    ->label(__('Send password reset email'))
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                    ->requiresConfirmation()
                    ->modalHeading(__('Send password reset email'))
                    ->modalDescription(__('Should a password reset email be sent to this user?'))
                    ->modalSubmitActionLabel(__('Yes, send password reset email'))
                    ->action(function (User $record): void {
                        $status = app(UserAdministrationService::class)->sendPasswordResetLink(
                            actor: self::currentUser(),
                            target: $record,
                        );

                        if ($status === Password::RESET_LINK_SENT) {
                            Notification::make()
                                ->title(__('Password reset email sent'))
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Password reset email could not be sent'))
                            ->body(trans($status))
                            ->danger()
                            ->send();
                    }),
                Actions\Action::make('sendVerificationEmail')
                    ->label(__('Send verification email'))
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                    ->hidden(fn (User $record): bool => $record->hasVerifiedEmail())
                    ->requiresConfirmation()
                    ->modalHeading(__('Send verification email'))
                    ->modalDescription(__('Should a verification email be sent to this user?'))
                    ->modalSubmitActionLabel(__('Yes, send verification email'))
                    ->action(function (User $record): void {
                        if (! app(UserAdministrationService::class)->sendVerificationEmail(self::currentUser(), $record)) {
                            Notification::make()
                                ->title(__('Email address is already verified'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Verification email sent'))
                            ->success()
                            ->send();
                    }),
                Actions\Action::make('changeRole')
                    ->visible(fn (): bool => self::currentUserCanManageRoles())
                    ->label(__('Change role'))
                    ->icon('heroicon-o-identification')
                    ->modalHeading(__('Change user role'))
                    ->fillForm(fn (User $record): array => [
                        'role' => self::currentRole($record)->value,
                    ])
                    ->form([
                        Radio::make('role')
                            ->label(__('Role'))
                            ->required()
                            ->options(self::roleOptions()),
                    ])
                    ->action(function (User $record, array $data): void {
                        $changed = app(UserAdministrationService::class)->changeRole(
                            actor: self::currentUser(),
                            target: $record,
                            role: Role::from($data['role']),
                        );

                        if (! $changed) {
                            Notification::make()
                                ->title(__('Action cancelled'))
                                ->body(__('The last admin cannot lose admin rights.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Role updated'))
                            ->success()
                            ->send();
                    }),
            ])
                ->label(__('Actions'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button(),
            Actions\DeleteAction::make()
                ->visible(fn (User $record): bool => UserResource::canManageRecord($record))
                ->before(function (Actions\DeleteAction $action, User $record) {
                    app(UserAdministrationService::class)->ensureCanManageUser(self::currentUser(), $record);

                    if ($record->isAdmin() && ! User::hasOtherAdmin($record->id)) {
                        Notification::make()
                            ->title(__('Action cancelled'))
                            ->body(__('The last admin cannot be deleted.'))
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                }),
        ];
    }

    /**
     * The user's effective single role for the picker; admin wins over
     * manager for users that still hold both from the old multi-role UI.
     */
    private static function currentRole(User $record): Role
    {
        if ($record->isAdmin()) {
            return Role::Admin;
        }

        if ($record->hasRole(Role::Manager->value)) {
            return Role::Manager;
        }

        return Role::User;
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach (Role::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
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
