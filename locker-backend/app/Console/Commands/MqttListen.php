<?php

namespace App\Console\Commands;

use App\Mqtt\Handlers\AbstractInboundMqttHandler;
use App\Mqtt\Handlers\CommandResponseHandler;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Handlers\HeartbeatHandler;
use App\Mqtt\Handlers\RegistrationHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Contracts\MqttClient;
use PhpMqtt\Client\Facades\MQTT;

class MqttListen extends Command
{
    /** @var string */
    protected $signature = 'mqtt:listen';

    /** @var string */
    protected $description = 'Starts a long-running process to listen for MQTT messages.';

    public function __construct(
        private readonly RegistrationHandler $registrationHandler,
        private readonly HeartbeatHandler $heartbeatHandler,
        private readonly CommandResponseHandler $commandResponseHandler,
        private readonly DeviceEventHandler $deviceEventHandler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting MQTT listener...');
        Log::info('Starting MQTT listener...');

        try {
            $mqtt = MQTT::connection('listener');

            foreach ($this->handlers() as $handler) {
                $this->subscribe($mqtt, $handler);
            }

            // Keep the client loop alive and allow internal sleep to avoid busy-waiting
            $mqtt->loop(true);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array<int, AbstractInboundMqttHandler>
     */
    private function handlers(): array
    {
        return [
            $this->registrationHandler,
            $this->heartbeatHandler,
            $this->commandResponseHandler,
            $this->deviceEventHandler,
        ];
    }

    private function subscribe(
        MqttClient $mqtt,
        AbstractInboundMqttHandler $handler,
    ): void {
        $this->info('Subscribing to: '.$handler->topicPattern());

        $mqtt->subscribe(
            $handler->topicPattern(),
            static fn (string $topic, string $message) => $handler->handleMessage($topic, $message),
            1,
        );
    }
}
