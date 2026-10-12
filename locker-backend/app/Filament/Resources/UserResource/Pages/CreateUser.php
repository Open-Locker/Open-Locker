<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tempPassword = Str::random(32);
        $data['password'] = Hash::make($tempPassword);

        /** @var User $user */
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
