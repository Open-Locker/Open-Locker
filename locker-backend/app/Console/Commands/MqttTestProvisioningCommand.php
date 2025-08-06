<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use PhpMqtt\Client\MqttClient;

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
    protected $description = 'Publishes a test message to the MQTT registration topic and listens for the reply.';

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

        try {
            // Dynamically override the client_id in the configuration for this specific run.
            // This ensures the connection uses the same unique ID we use for the reply topic.
            config(['mqtt-client.connections.provisioning.client_id' => $clientId]);

            /** @var MqttClient $mqtt */
            $mqtt = MQTT::connection('provisioning');

            $this->info("Connecting with unique Client ID: {$mqtt->getClientId()}");

            $this->info("Subscribing to reply topic: {$replyToTopic}");
            $mqtt->subscribe($replyToTopic, function (string $topic, string $message) use ($mqtt) {
                $this->info("<<< Response received on [{$topic}]");
                $this->line($message);
                $this->info('Test successful. Closing connection.');
                $mqtt->interrupt();
            });

            $this->info(">>> Publishing registration request to [{$registrationTopic}]");
            $mqtt->publish($registrationTopic, $payload, 2);

            $this->info('Waiting for provisioning response... (Press CTRL+C to abort)');
            $mqtt->loop(true);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
            $this->warn('Did you add MQTT_PROVISIONING_USERNAME and MQTT_PROVISIONING_PASSWORD to your .env file?');

            return Command::FAILURE;
        }
    }
}
