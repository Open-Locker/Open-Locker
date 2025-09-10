<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class MqttUserService
{
    private const DEVICE_COMMON_ROLE = 'device-common';

    /**
     * Create a new MQTT user according to selected driver.
     */
    public function createUser(string $username, string $password): void
    {

        $this->dynsecCreateClient($username, $password);
    }

    /**
     * Delete an MQTT user according to selected driver.
     */
    public function deleteUser(string $username): void
    {
        $this->dynsecDeleteClient($username);
    }

    private function dynsecCreateClient(string $username, string $password): void
    {
        // Use dedicated dynsec connection (admin credentials)
        $client = MQTT::connection('dynsec');

        $request = [
            'commands' => [
                // Ensure client exists
                [
                    'command' => 'createClient',
                    'username' => $username,
                    'password' => $password,
                ],
                // Ensure a shared base role for all devices exists and assign it to the client
                [
                    'command' => 'createRole',
                    'rolename' => self::DEVICE_COMMON_ROLE,
                ],
                [
                    'command' => 'addRoleACL',
                    'rolename' => self::DEVICE_COMMON_ROLE,
                    'acls' => [
                        [
                            'acltype' => 'subscribeLiteral',
                            'topic' => 'server/status',
                            'allow' => true,
                        ],
                    ],
                ],
                [
                    'command' => 'addClientRole',
                    'username' => $username,
                    'rolename' => self::DEVICE_COMMON_ROLE,
                ],
                // Create a minimal per-client role and assign it, so the device can publish its state.
                [
                    'command' => 'createRole',
                    'rolename' => "device-{$username}",
                ],
                [
                    'command' => 'addRoleACL',
                    'rolename' => "device-{$username}",
                    'acls' => [
                        [
                            'acltype' => 'publishClientSend',
                            'topic' => "locker/{$username}/state",
                            'allow' => true,
                        ],
                        [
                            'acltype' => 'publishClientSend',
                            'topic' => "locker/{$username}/status",
                            'allow' => true,
                        ],
                        [
                            'acltype' => 'subscribeLiteral',
                            'topic' => "locker/{$username}/command",
                            'allow' => true,
                        ],
                    ],
                ],
                [
                    'command' => 'addClientRole',
                    'username' => $username,
                    'rolename' => "device-{$username}",
                ],
            ],
        ];

        // Note: dynsec responses are published to $CONTROL/dynamic-security/v1/response
        // We publish fire-and-forget here; callers can add verification if needed.
        $client->publish('$CONTROL/dynamic-security/v1', json_encode($request), 0);
        Log::info('Dynsec createClient published.', ['username' => $username]);
    }

    private function dynsecDeleteClient(string $username): void
    {
        // Use dedicated dynsec connection (admin credentials)
        $client = MQTT::connection('dynsec');

        $request = [
            'commands' => [
                // Best-effort: remove assigned roles first
                [
                    'command' => 'removeClientRole',
                    'username' => $username,
                    'rolename' => "device-{$username}",
                ],
                [
                    'command' => 'removeClientRole',
                    'username' => $username,
                    'rolename' => self::DEVICE_COMMON_ROLE,
                ],
                [
                    'command' => 'deleteClient',
                    'username' => $username,
                ],
                // Cleanup the per-client role to avoid orphaned roles
                [
                    'command' => 'deleteRole',
                    'rolename' => "device-{$username}",
                ],
            ],
        ];

        $client->publish('$CONTROL/dynamic-security/v1', json_encode($request), 0);
        Log::info('Dynsec deleteClient published.', ['username' => $username]);
    }
}
