<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $data['password'] = bcrypt('test');
        $user = static::getModel()::create($data);

//        $notification->url = filament()->getPanel('admin')->getResetPasswordUrl($token, $user);
        $user->is_admin_since = now();
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    protected function afterCreate(): void
    {
        $user = $this->record;
        $token = app('auth.password.broker')->createToken($user);
        $notification = new \Filament\Notifications\Auth\ResetPassword($token);
        $notification->url = \Filament\Facades\Filament::getResetPasswordUrl($token, $user);
        $user->notify($notification);
    }
}
