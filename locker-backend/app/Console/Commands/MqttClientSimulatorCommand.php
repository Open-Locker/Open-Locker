<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Facades\MQTT;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\MqttClient as BaseMqttClient;

class MqttClientSimulatorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:client-simulator
        {token : The registration token to use for the MQTT topic}
        {--host= : Override the MQTT broker host (applies to provisioning + device simulation)}
        {--port= : Override the MQTT broker port (applies to provisioning + device simulation)}
        {--heartbeat-interval=10 : Heartbeat interval in seconds (default: 10)}
        {--open-result=success : open_compartment result: success|error (default: success)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulates a device: provisions via MQTT, connects with issued credentials, subscribes to locker/{uuid}/command, and optionally publishes heartbeats and command responses.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $token = (string) $this->argument('token');
        $clientId = 'test-publisher-'.uniqid();
        $registrationTopic = "locker/register/{$token}";
        $replyToTopic = "locker/provisioning/reply/{$clientId}";
        $payload = json_encode(['client_id' => $clientId]);

        $provisionResult = null; // ['status' => 'success|error', 'user' => ..., 'password' => ...]

        $overrideHost = $this->option('host');
        $overridePort = $this->option('port');

        if (is_string($overrideHost) && $overrideHost !== '') {
            config([
                'mqtt-client.connections.provisioning.host' => $overrideHost,
                'mqtt-client.connections.listener.host' => $overrideHost,
            ]);
        }

        if (is_string($overridePort) && $overridePort !== '' && is_numeric($overridePort)) {
            $port = (int) $overridePort;
            if ($port > 0) {
                config([
                    'mqtt-client.connections.provisioning.port' => $port,
                    'mqtt-client.connections.listener.port' => $port,
                ]);
            }
        }

        $heartbeatIntervalSeconds = max(1, (int) $this->option('heartbeat-interval'));
        $openResult = (string) $this->option('open-result');
        $openResult = $openResult === 'error' ? 'error' : 'success';

        try {
            // Use a unique client_id for this run on the provisioning connection
            config(['mqtt-client.connections.provisioning.client_id' => $clientId]);

            /** @var MqttClient $mqtt */
            $mqtt = MQTT::connection('provisioning');

            $this->info("Connecting with unique Client ID: {$mqtt->getClientId()}");
            $this->info('Broker: '.(string) config('mqtt-client.connections.provisioning.host').':'.(string) config('mqtt-client.connections.provisioning.port'));
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
            $mqtt->publish($registrationTopic, (string) $payload, 1);

            $this->info('Waiting for provisioning response... (Press CTRL+C to abort)');
            $mqtt->loop(true);

            if (! is_array($provisionResult) || ($provisionResult['status'] ?? null) !== 'success') {
                $this->warn('No success credentials to test. Exiting.');

                return Command::SUCCESS;
            }

            sleep(2);

            // Phase 2: Simulate device using the issued credentials
            $lockerUuid = (string) $provisionResult['user'];
            $lockerPassword = (string) $provisionResult['password'];
            $deviceClientId = 'device-test-'.uniqid();
            $stateTopic = "locker/{$lockerUuid}/state";
            $commandTopic = "locker/{$lockerUuid}/command";
            $responseTopic = "locker/{$lockerUuid}/response";

            // Connect the simulated device to the same broker host/port as our listener
            $host = (string) config('mqtt-client.connections.listener.host', 'mqtt');
            $port = (int) config('mqtt-client.connections.listener.port', 1883);

            $settings = (new ConnectionSettings)
                ->setUsername($lockerUuid)
                ->setPassword($lockerPassword)
                ->setKeepAliveInterval(10)
                ->setUseTls(false);

            $base = new BaseMqttClient($host, $port, $deviceClientId);
            $base->connect($settings);
            $this->info("[device] Connected with client_id: {$deviceClientId} as username: {$lockerUuid}");
            $this->info("[device] Broker: {$host}:{$port}");

            $seenTransactionIds = [];
            $transactionResponses = [];

            $base->subscribe($commandTopic, function (string $topic, string $message) use (
                &$seenTransactionIds,
                &$transactionResponses,
                $responseTopic,
                $openResult,
                $base
            ) {
                $this->info("<<< [device] Command received on [{$topic}]");
                $this->line($message);

                $decoded = json_decode($message, true);
                if (! is_array($decoded)) {
                    return;
                }

                $transactionId = (string) ($decoded['transaction_id'] ?? '');

                if ($transactionId !== '' && isset($seenTransactionIds[$transactionId])) {
                    // Simulate idempotency: resend the same response if we have it.
                    if (isset($transactionResponses[$transactionId])) {
                        $this->info(">>> [device] Duplicate transaction {$transactionId}, resending response to [{$responseTopic}] (QoS 1)");
                        $base->publish($responseTopic, (string) $transactionResponses[$transactionId], 1);
                    }

                    return;
                }

                if ($transactionId !== '') {
                    $seenTransactionIds[$transactionId] = true;
                }

                $action = $decoded['action'] ?? null;

                if ($action === 'apply_config') {
                    $data = $decoded['data'] ?? [];
                    $configHash = is_array($data) && isset($data['config_hash']) && is_string($data['config_hash'])
                        ? $data['config_hash']
                        : null;

                    if (! is_string($configHash) || strlen($configHash) !== 64) {
                        $compartments = is_array($data) && isset($data['compartments']) && is_array($data['compartments'])
                            ? $data['compartments']
                            : [];
                        $configHash = hash('sha256', json_encode($compartments, JSON_UNESCAPED_SLASHES));
                    }

                    $response = [
                        'type' => 'command_response',
                        'action' => 'apply_config',
                        'result' => 'success',
                        'transaction_id' => $transactionId !== '' ? $transactionId : null,
                        'timestamp' => now()->toIso8601String(),
                        'applied_config_hash' => $configHash,
                        'message' => 'Config applied (simulated).',
                    ];

                    $json = json_encode($response);
                    if ($transactionId !== '') {
                        $transactionResponses[$transactionId] = $json;
                    }

                    $this->info(">>> [device] Publishing simulated apply_config response to [{$responseTopic}] (QoS 1)");
                    $base->publish($responseTopic, (string) $json, 1);

                    return;
                }

                if ($action !== 'open_compartment') {
                    return;
                }

                $result = $openResult;

                $response = [
                    'type' => 'command_response',
                    'action' => 'open_compartment',
                    'result' => $result,
                    'transaction_id' => $transactionId !== '' ? $transactionId : null,
                    'timestamp' => now()->toIso8601String(),
                    'message' => $result === 'success'
                        ? 'Compartment opened successfully (simulated).'
                        : 'Could not open compartment (simulated).',
                ];

                if ($result !== 'success') {
                    $response['error_code'] = 'SIMULATED_ERROR';
                }

                $json = json_encode($response);
                if ($transactionId !== '') {
                    $transactionResponses[$transactionId] = $json;
                }

                $this->info(">>> [device] Publishing simulated open_compartment response to [{$responseTopic}] (QoS 1)");
                $base->publish($responseTopic, (string) $json, 1);
            }, 1);

            $this->info("[device] Subscribed to command topic: {$commandTopic} (QoS 1)");
            $this->info('Now trigger an "Open" from Filament for this LockerBank to observe the command here.');

            // Use a stable loop start timestamp so internal timeouts / keepalive work as expected.
            $loopStartedAt = microtime(true);

            $this->info(">>> Publishing heartbeat to [{$stateTopic}] every {$heartbeatIntervalSeconds}s (QoS 1). Press CTRL+C to stop.");

            // Continuous heartbeat loop
            while (true) {
                $heartbeatPayload = json_encode([
                    'type' => 'state',
                    'state' => 'heartbeat',
                    'data' => [
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]);

                $base->publish($stateTopic, (string) $heartbeatPayload, 1);
                $this->info(">>> Publishing heartbeat to [{$stateTopic}]");

                $nextHeartbeatAt = microtime(true) + $heartbeatIntervalSeconds;
                while (microtime(true) < $nextHeartbeatAt) {
                    $base->loopOnce($loopStartedAt, true);
                    usleep(200_000);
                }
            }
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
            $this->warn('Did you add MQTT_PROVISIONING_USERNAME and MQTT_PROVISIONING_PASSWORD to your .env file?');

            return Command::FAILURE;
        }
    }
}
