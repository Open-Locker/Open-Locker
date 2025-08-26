<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MqttUserService
{
    private const DEVICE_COMMON_ROLE = 'device-common';

    /**
     * Driver to use for user management: 'password_file' or 'dynsec'.
     */
    private string $driver;

    /**
     * Path to mosquitto password file when using password_file driver.
     */
    private string $passwordFilePath;

    public function __construct()
    {
        $this->driver = config('services.mqtt_auth.driver', env('MQTT_AUTH_DRIVER', 'password_file'));
        $this->passwordFilePath = '/mosquitto/config/password.conf';
    }

    /**
     * Create a new MQTT user according to selected driver.
     */
    public function createUser(string $username, string $password): void
    {
        if ($this->driver === 'dynsec') {
            $this->dynsecCreateClient($username, $password);

            return;
        }

        $this->passwordFileCreateUser($username, $password);
    }

    /**
     * Delete an MQTT user according to selected driver.
     */
    public function deleteUser(string $username): void
    {
        if ($this->driver === 'dynsec') {
            $this->dynsecDeleteClient($username);

            return;
        }

        $this->passwordFileDeleteUser($username);
    }

    private function passwordFileCreateUser(string $username, string $password): void
    {
        $command = [
            'mosquitto_passwd',
            '-b',
            $this->passwordFilePath,
            $username,
            $password,
        ];

        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Failed to create MQTT user.', [
                'username' => $username,
                'error' => $process->getErrorOutput(),
            ]);
            throw new ProcessFailedException($process);
        }

        Log::info('Successfully created MQTT user.', ['username' => $username]);
    }

    private function passwordFileDeleteUser(string $username): void
    {
        $command = [
            'mosquitto_passwd',
            '-D',
            $this->passwordFilePath,
            $username,
        ];

        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Failed to delete MQTT user.', [
                'username' => $username,
                'error' => $process->getErrorOutput(),
            ]);
            throw new ProcessFailedException($process);
        }

        Log::info('Successfully deleted MQTT user.', ['username' => $username]);
    }

    private function dynsecCreateClient(string $username, string $password): void
    {
        // Use default connection; configure it with admin credentials to run dynsec commands.
        $client = MQTT::connection();

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
        // Use default connection; configure it with admin credentials to run dynsec commands.
        $client = MQTT::connection();

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
