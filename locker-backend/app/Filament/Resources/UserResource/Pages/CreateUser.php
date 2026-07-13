<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AuthService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $tempPassword = Str::random(32);
        $data['password'] = Hash::make($tempPassword);

        /** @var \App\Models\User $user */
        $user = static::getModel()::create($data);

        $passwordResetService = app(AuthService::class);
        $status = $passwordResetService->sendResetLink($user->email);

        Notification::make()
            ->title(__('User created'))
            ->body(__('Password reset link sent.'))
            ->success()
            ->send();

        return $user;
    }
}
