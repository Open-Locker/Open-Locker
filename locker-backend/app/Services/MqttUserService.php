<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MqttUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MqttUserService
{
    /**
     * Create a new MQTT user according to selected driver.
     */
    public function createUser(string $username, string $password, string $lockerBankId): void
    {
        $user = MqttUser::firstOrNew(['username' => $username]);
        $user->password_hash = Hash::make($password);
        $user->locker_bank_id = $lockerBankId;
        $user->enabled = true;
        $user->save();
        Log::info('App MQTT user upserted.', ['username' => $username]);
    }

    /**
     * Delete an MQTT user according to selected driver.
     */
    public function deleteUser(string $username): void
    {
        MqttUser::where('username', $username)->delete();
        Log::info('App MQTT user deleted.', ['username' => $username]);
    }
}
