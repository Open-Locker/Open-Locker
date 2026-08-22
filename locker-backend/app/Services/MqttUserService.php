<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MqttUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MqttUserService
{
    /**
     * Issue a new MQTT identity.
     *
     * An insert, never an upsert: usernames are opaque and issued once, so a
     * collision means a caller passed a username that was already handed out —
     * which must fail loudly rather than silently re-password an existing row.
     */
    public function createUser(string $username, string $password, string $lockerBankId): void
    {
        $user = new MqttUser;
        $user->username = $username;
        $user->password_hash = Hash::make($password);
        $user->locker_bank_id = $lockerBankId;
        $user->enabled = true;
        $user->revoked_at = null;
        $user->save();

        Log::info('App MQTT identity issued.', ['username' => $username]);
    }

    /**
     * Retire every live identity of a locker bank.
     *
     * Rows are disabled and kept, never deleted: the unique index on `username`
     * is what stops a retired identity from ever being issued again, so deleting
     * would hand its username back for reuse. Because an already-connected
     * session is authorised per operation against this row, disabling it also
     * ends that session's access without needing to evict it at the broker.
     *
     * @return int number of identities retired
     */
    public function revokeForLockerBank(string $lockerBankId): int
    {
        $revoked = MqttUser::query()
            ->where('locker_bank_id', $lockerBankId)
            ->where('enabled', true)
            ->update(['enabled' => false, 'revoked_at' => now()]);

        if ($revoked > 0) {
            Log::info('App MQTT identities revoked.', [
                'locker_bank_id' => $lockerBankId,
                'count' => $revoked,
            ]);
        }

        return $revoked;
    }
}
