<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Facades\MQTT;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\MqttClient as BaseMqttClient;

class MqttTestProvisioningCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:test-provisioning {token : The registration token to use for the MQTT topic}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publishes a test message to the MQTT registration topic and listens for the reply, then tests the issued credentials by sending a heartbeat.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = $this->argument('token');
        $clientId = 'test-publisher-'.uniqid();
        $registrationTopic = "locker/register/{$token}";
        $replyToTopic = "locker/provisioning/reply/{$clientId}";
        $payload = json_encode(['client_id' => $clientId]);

        $provisionResult = null; // ['status' => 'success|error', 'user' => ..., 'password' => ...]

        try {
            // Use a unique client_id for this run on the provisioning connection
            config(['mqtt-client.connections.provisioning.client_id' => $clientId]);

            /** @var MqttClient $mqtt */
            $mqtt = MQTT::connection('provisioning');

            $this->info("Connecting with unique Client ID: {$mqtt->getClientId()}");
            $this->info("Subscribing to reply topic: {$replyToTopic}");

            $mqtt->subscribe($replyToTopic, function (string $topic, string $message) use (&$provisionResult, $mqtt) {
                $this->info("<<< Response received on [{$topic}]");
                $this->line($message);

                $decoded = json_decode($message, true);
                if (is_array($decoded) && ($decoded['status'] ?? null) === 'success') {
                    $provisionResult = [
                        'status' => 'success',
                        'user' => $decoded['data']['mqtt_user'] ?? null,
                        'password' => $decoded['data']['mqtt_password'] ?? null,
                    ];
                    $this->info('Provisioning succeeded, credentials captured.');
                } else {
                    $provisionResult = ['status' => 'error'];
                    $this->warn('Provisioning error received.');
                }

                $mqtt->interrupt();
            }, 1);

            $this->info(">>> Publishing registration request to [{$registrationTopic}]");
            $mqtt->publish($registrationTopic, $payload, 1);

            $this->info('Waiting for provisioning response... (Press CTRL+C to abort)');
            $mqtt->loop(true);

            if (! is_array($provisionResult) || ($provisionResult['status'] ?? null) !== 'success') {
                $this->warn('No success credentials to test. Exiting.');

                return Command::SUCCESS;
            }

            sleep(10);

            // Phase 2: Test credentials by sending a heartbeat using base client
            $lockerUuid = (string) $provisionResult['user'];
            $lockerPassword = (string) $provisionResult['password'];
            $deviceClientId = 'device-test-'.uniqid();
            $stateTopic = "locker/{$lockerUuid}/state";
            $heartbeatPayload = json_encode([
                'event' => 'heartbeat',
                'data' => [
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            $host = config('mqtt-client.connections.default.host', 'mosquitto');
            $port = (int) config('mqtt-client.connections.default.port', 1883);

            $settings = (new ConnectionSettings)
                ->setUsername($lockerUuid)
                ->setPassword($lockerPassword)
                ->setKeepAliveInterval(10)
                ->setUseTls(false);

            $base = new BaseMqttClient($host, $port, $deviceClientId);
            $base->connect($settings);
            $this->info("[device] Connected with client_id: {$deviceClientId} as username: {$lockerUuid}");

            $this->info(">>> Publishing heartbeat to [{$stateTopic}] (QoS 1)");
            $base->publish($stateTopic, $heartbeatPayload, 1);
            // Wait until QoS1 ack queues are cleared, then disconnect
            $base->loop(true, true);
            $base->disconnect();
            $this->info('Heartbeat published and acknowledged (QoS 1).');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
            $this->warn('Did you add MQTT_PROVISIONING_USERNAME and MQTT_PROVISIONING_PASSWORD to your .env file?');

            return Command::FAILURE;
        }
    }
}
