<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class MqttUserService
{
    protected string $passwordFilePath;

    public function __construct()
    {
        // This is the path inside the container, now mounted from the mosquitto config volume.
        $this->passwordFilePath = '/mosquitto/config/password.conf';
    }

    /**
     * Creates a new user in the Mosquitto password file.
     *
     * @throws ProcessFailedException
     */
    public function createUser(string $username, string $password): void
    {
        $command = [
            'mosquitto_passwd',
            '-b', // batch mode
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
}
