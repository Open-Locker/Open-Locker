<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MqttUserService
{
    private const DEVICE_COMMON_ROLE = 'device-common';

    /**
     * Create a new MQTT user according to selected driver.
     */
    public function createUser(string $username, string $password): void
    {
        $this->createDbUser($username, $password);
    }

    /**
     * Delete an MQTT user according to selected driver.
     */
    public function deleteUser(string $username): void
    {
        $this->deleteDbUser($username);
    }

    private function createDbUser(string $username, string $password): void
    {
        // VerneMQ vmq_diversity MySQL with password_hash_method=sha256 expects an unsalted SHA-256 hex hash
        $hash = hash('sha256', $password);

        $publishAcl = json_encode([
            ['pattern' => 'locker/%u/state'],
            ['pattern' => 'locker/%u/status'],
        ]);

        $subscribeAcl = json_encode([
            ['pattern' => 'locker/%u/command'],
        ]);

        DB::table('vmq_auth_acl')->updateOrInsert(
            [
                'mountpoint' => '',
                // Bind client_id to username to ensure strong coupling of device identity
                'client_id' => $username,
                'username' => $username,
            ],
            [
                'password' => $hash,
                'publish_acl' => $publishAcl,
                'subscribe_acl' => $subscribeAcl,
            ],
        );

        Log::info('VerneMQ DB user upserted.', ['username' => $username]);
    }

    private function deleteDbUser(string $username): void
    {
        DB::table('vmq_auth_acl')
            ->where('mountpoint', '')
            ->where('username', $username)
            ->delete();

        Log::info('VerneMQ DB user deleted.', ['username' => $username]);
    }
}
