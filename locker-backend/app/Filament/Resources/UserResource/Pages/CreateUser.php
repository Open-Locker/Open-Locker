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

        $user = static::getModel()::create($data);

        $passwordResetService = app(AuthService::class);
        $status = $passwordResetService->sendResetLink($user->email);

        Notification::make()
            ->title('Nutzer erstellt')
            ->body('Link zum Zurücksetzen des Passworts versendet.')
            ->success()
            ->send();

        return $user;
    }
}
