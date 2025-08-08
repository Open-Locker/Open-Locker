<?php

namespace App\Console\Commands;

use App\Mqtt\Handlers\RegistrationHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class MqttListen extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:listen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts a long-running process to listen for MQTT messages.';

    public function __construct(private readonly RegistrationHandler $registrationHandler)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting MQTT listener...');

        try {
            $mqtt = MQTT::connection();

            $this->info('Subscribing to: locker/register/+');
            $mqtt->subscribe('locker/register/+', function (string $topic, string $message) {
                Log::info('MQTT message received', [
                    'topic' => $topic,
                    'message' => $message,
                ]);

                $payload = json_decode($message, true) ?? [];
                if (! is_array($payload)) {
                    Log::warning('Invalid JSON payload received', [
                        'topic' => $topic,
                        'raw' => $message,
                    ]);

                    return;
                }

                $this->registrationHandler->handle($topic, $payload);
            }, 1);

            $mqtt->loop(true);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
