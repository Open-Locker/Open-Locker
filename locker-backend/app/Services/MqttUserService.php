<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MqttUserService
{
    /**
     * Create a new MQTT user according to selected driver.
     */
    public function createUser(string $username, string $password): void
    {
        $this->createLockerUser($username, $password);
    }

    /**
     * Delete an MQTT user according to selected driver.
     */
    public function deleteUser(string $username): void
    {
        $this->deleteDbUser($username);
    }

    /**
     * Ensure required system users exist with correct ACLs.
     */
    public function ensureSystemUsers(): void
    {
        $this->ensureBackendUser();
        $this->ensureProvisioningClientUser();
    }

    /**
     * Ensure backend user exists with full ACLs.
     * Throws an exception if the backend user is not found.
     *
     * @throws \Exception
     */
    public function ensureBackendUser(): void
    {
        $backendUser = config('mqtt-client.system.backend_username');
        $backendPass = config('mqtt-client.system.backend_password');
        if (! empty($backendUser) && ! empty($backendPass)) {
            $this->createBackendUser($backendUser, $backendPass);
        } else {
            throw new \Exception('MQTT Backend Credentials not found');
        }
    }

    /**
     * Ensure provisioning client user exists with restricted ACLs.
     * Throws an exception if the provisioning client user is not found.
     *
     * @throws \Exception
     */
    public function ensureProvisioningClientUser(): void
    {
        $provUser = config('mqtt-client.system.provisioning_username');
        $provPass = config('mqtt-client.system.provisioning_password');
        if (! empty($provUser) && ! empty($provPass)) {
            $this->createProvisioningClient($provUser, $provPass);
        } else {
            throw new \Exception('MQTT Provisioning Credentials not found');
        }
    }

    private function updateOrInsertMQTTUser(string $username, string $password, string $publishAcl, string $subscribeAcl): void
    {
        // VerneMQ vmq_diversity MySQL with password_hash_method=sha256 expects an unsalted SHA-256 hex hash
        $hash = hash('sha256', $password);

        DB::table('vmq_auth_acl')->updateOrInsert(
            [
                'mountpoint' => '',
                'client_id' => $username,
                'username' => $username,
            ],
            [
                'password' => $hash,
                'publish_acl' => $publishAcl,
                'subscribe_acl' => $subscribeAcl,
            ],
        );

        Log::info('VerneMQ provisioning user upserted.', ['username' => $username]);
    }

    private function createLockerUser(string $username, string $password): void
    {

        // Default device ACLs bound to username
        $publishAcl = json_encode([
            ['pattern' => 'locker/%u/state'],
            ['pattern' => 'locker/%u/status'],
        ]);
        $subscribeAcl = json_encode([
            ['pattern' => 'locker/%u/command'],
        ]);

        $this->updateOrInsertMQTTUser($username, $password, $publishAcl, $subscribeAcl);
    }

    /**
     * Creates provisioning client with restricted ACLs.
     */
    private function createBackendUser(string $username, string $password): void
    {
        $hash = hash('sha256', $password);

        $publishAcl = json_encode([
            ['pattern' => 'locker/#'],
        ]);
        $subscribeAcl = json_encode([
            ['pattern' => 'locker/#'],
            ['pattern' => 'server/status'],
        ]);

        $this->updateOrInsertMQTTUser($username, $password, $publishAcl, $subscribeAcl);
    }

    /**
     * Creates provisioning client with restricted ACLs.
     */
    private function createProvisioningClient(string $username, string $password): void
    {
        $hash = hash('sha256', $password);

        $publishAcl = json_encode([
            ['pattern' => 'locker/register/+'],
        ]);

        // Limit read to reply topic for its own client id via %c
        $subscribeAcl = json_encode([
            ['pattern' => 'locker/provisioning/reply/%c'],
        ]);

        $this->updateOrInsertMQTTUser($username, $password, $publishAcl, $subscribeAcl);
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
