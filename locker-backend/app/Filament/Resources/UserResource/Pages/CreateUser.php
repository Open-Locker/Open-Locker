<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserAdministrationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tempPassword = Str::random(32);
        $actor = Auth::user();

        $user = app(UserAdministrationService::class)->createMember([
            'email' => (string) $data['email'],
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'password' => Hash::make($tempPassword),
        ], $actor instanceof User ? $actor->id : null);

        $passwordResetService = app(AuthService::class);
        $status = $passwordResetService->sendResetLink($user->email);

        // Same title whether the email was new or already had an account. The body
        // reports the broker, which throttles a link requested in the last minute.
        Notification::make()
            ->title(__('User created'))
            ->body($status === Password::RESET_LINK_SENT ? __('Password reset link sent.') : trans($status))
            ->success()
            ->send();

        return $user;
    }
}
