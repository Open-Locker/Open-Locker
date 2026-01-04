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
        {--no-heartbeat : Do not publish heartbeats}
        {--listen-seconds=60 : When --no-heartbeat is set, keep listening for commands for N seconds (0 = forever)}
        {--auto-status : Publish a simulated status response for open_compartment commands}
        {--auto-status-result=success : For --auto-status, publish success|error}
        {--auto-status-delay-ms=0 : Delay before publishing the status response (milliseconds)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulates a device: provisions via MQTT, connects with issued credentials, subscribes to locker/{uuid}/command, and optionally publishes heartbeats and status replies.';

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
        $noHeartbeat = (bool) $this->option('no-heartbeat');
        $listenSeconds = (int) $this->option('listen-seconds');

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

        $autoStatus = (bool) $this->option('auto-status');
        $autoStatusResult = (string) $this->option('auto-status-result');
        $autoStatusDelayMs = max(0, (int) $this->option('auto-status-delay-ms'));

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
            $statusTopic = "locker/{$lockerUuid}/status";

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

            $openCommandReceived = false;
            $seenTransactionIds = [];

            $base->subscribe($commandTopic, function (string $topic, string $message) use (
                &$openCommandReceived,
                &$seenTransactionIds,
                $autoStatus,
                $autoStatusDelayMs,
                $autoStatusResult,
                $statusTopic,
                $base
            ) {
                $this->info("<<< [device] Command received on [{$topic}]");
                $this->line($message);

                $decoded = json_decode($message, true);
                if (! is_array($decoded)) {
                    return;
                }

                if (($decoded['action'] ?? null) !== 'open_compartment') {
                    return;
                }

                $openCommandReceived = true;

                $transactionId = (string) ($decoded['transaction_id'] ?? '');
                if ($transactionId !== '' && isset($seenTransactionIds[$transactionId])) {
                    return;
                }

                if ($transactionId !== '') {
                    $seenTransactionIds[$transactionId] = true;
                }

                if (! $autoStatus) {
                    return;
                }

                if ($autoStatusDelayMs > 0) {
                    usleep($autoStatusDelayMs * 1000);
                }

                $status = $autoStatusResult === 'error' ? 'error' : 'success';
                $event = $status === 'success' ? 'action_completed' : 'action_failed';

                $response = [
                    'event' => $event,
                    'action' => 'open_compartment',
                    'status' => $status,
                    'transaction_id' => $transactionId !== '' ? $transactionId : null,
                    'timestamp' => now()->toIso8601String(),
                    'message' => $status === 'success'
                        ? 'Compartment opened successfully (simulated).'
                        : 'Could not open compartment (simulated).',
                ];

                if ($status !== 'success') {
                    $response['error_code'] = 'SIMULATED_ERROR';
                }

                $data = $decoded['data'] ?? [];
                if (is_array($data) && array_key_exists('compartment_number', $data)) {
                    $response['data'] = [
                        'compartment_number' => $data['compartment_number'],
                    ];
                }

                $json = json_encode($response);
                $this->info(">>> [device] Publishing simulated status to [{$statusTopic}] (QoS 1)");
                $base->publish($statusTopic, (string) $json, 1);
            }, 1);

            $this->info("[device] Subscribed to command topic: {$commandTopic} (QoS 1)");
            $this->info('Now trigger an "Open" from Filament for this LockerBank to observe the command here.');

            // Use a stable loop start timestamp so internal timeouts / keepalive work as expected.
            $loopStartedAt = microtime(true);

            if ($noHeartbeat) {
                if ($listenSeconds === 0) {
                    $this->info('No-heartbeat mode enabled. Listening for commands indefinitely (CTRL+C to stop)...');

                    while (true) {
                        $base->loopOnce($loopStartedAt, true);
                    }
                }

                $this->info("No-heartbeat mode enabled. Listening for commands for {$listenSeconds}s...");
                $deadline = microtime(true) + max(1, $listenSeconds);

                while (microtime(true) <= $deadline) {
                    $base->loopOnce($loopStartedAt, true);
                }

                $this->info('Done listening.');
                $base->disconnect();

                return Command::SUCCESS;
            }

            $this->info(">>> Publishing heartbeat to [{$stateTopic}] every 1s (QoS 1). Press CTRL+C to stop.");

            // Continuous heartbeat loop
            while (true) {
                $heartbeatPayload = json_encode([
                    'event' => 'heartbeat',
                    'data' => [
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]);

                $base->publish($stateTopic, (string) $heartbeatPayload, 1);
                $this->info(">>> Publishing heartbeat to [{$stateTopic}]");
                $base->loopOnce($loopStartedAt, true);
                sleep(1);
            }
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
            $this->warn('Did you add MQTT_PROVISIONING_USERNAME and MQTT_PROVISIONING_PASSWORD to your .env file?');

            return Command::FAILURE;
        }
    }
}
