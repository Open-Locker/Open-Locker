<?php

namespace App\Console\Commands;

use App\Mqtt\Handlers\AbstractInboundMqttHandler;
use App\Mqtt\Handlers\CommandResponseHandler;
use App\Mqtt\Handlers\CompartmentSnapshotHandler;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Handlers\LockerConnectionStateHandler;
use App\Mqtt\Handlers\LockerHeartbeatHandler;
use App\Mqtt\Handlers\RegistrationHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
        private readonly LockerHeartbeatHandler $lockerHeartbeatHandler,
        private readonly CompartmentSnapshotHandler $compartmentSnapshotHandler,
        private readonly LockerConnectionStateHandler $lockerConnectionStateHandler,
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

            $this->registerHeartbeat($mqtt);

            // Keep the client loop alive and allow internal sleep to avoid busy-waiting
            $mqtt->loop(true);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Emit a liveness pulse on each loop iteration (throttled), so mqtt:health
     * can detect a wedged-but-running listener. See ADR-0025.
     */
    private function registerHeartbeat(MqttClient $mqtt): void
    {
        $key = (string) config('mqtt-client.heartbeat.cache_key');
        $interval = (int) config('mqtt-client.heartbeat.interval');
        $lastBeat = 0.0;

        $mqtt->registerLoopEventHandler(function () use ($key, $interval, &$lastBeat): void {
            $now = microtime(true);
            if ($now - $lastBeat >= $interval) {
                Cache::put($key, time(), now()->addMinute());
                $lastBeat = $now;
            }
        });
    }

    /**
     * @return array<int, AbstractInboundMqttHandler>
     */
    private function handlers(): array
    {
        return [
            $this->registrationHandler,
            $this->lockerHeartbeatHandler,
            $this->compartmentSnapshotHandler,
            $this->lockerConnectionStateHandler,
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
