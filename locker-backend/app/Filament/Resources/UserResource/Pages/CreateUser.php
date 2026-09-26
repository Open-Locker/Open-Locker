<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserAdministrationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $user = app(UserAdministrationService::class)->addUser(
            $actor,
            (string) $data['first_name'],
            (string) $data['last_name'],
            (string) $data['email'],
        );

        if (! $user->wasRecentlyCreated) {
            Notification::make()
                ->title(__('User added'))
                ->body(__('This person already had an account, so it was added to this organization. They were notified by email.'))
                ->success()
                ->send();

            return $user;
        }

        app(AuthService::class)->sendResetLink($user->email);

        Notification::make()
            ->title(__('User created'))
            ->body(__('Password reset link sent.'))
            ->success()
            ->send();

        return $user;
    }

    /**
     * The messages above say whether an account was created or joined;
     * Filament's generic "Created" would contradict the second.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
