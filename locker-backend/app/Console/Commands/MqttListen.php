<?php

namespace App\Console\Commands;

use App\Aggregates\LockerBankAggregate;
use App\Models\LockerBank;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting MQTT listener...');

        try {
            $mqtt = MQTT::connection();

            $this->info('Subscribing to registration topic: locker/register/+');

            $mqtt->subscribe('locker/register/+', function (string $topic, string $message) {
                $this->info("Received message on topic: {$topic}");
                Log::info("Received registration request on topic [{$topic}]: {$message}");

                $provisioningToken = Str::after($topic, 'locker/register/');
                $payload = json_decode($message, true);
                $replyToTopic = 'locker/provisioning/reply/'.$payload['client_id'];

                Log::info("Attempting to find LockerBank with token: [{$provisioningToken}]");

                $lockerBank = LockerBank::where('provisioning_token', $provisioningToken)->first();

                if (! $lockerBank) {
                    Log::warning("No LockerBank found for provisioning token: {$provisioningToken}");

                    return;
                }

                Log::info("Found LockerBank with UUID: {$lockerBank->id}. Calling aggregate.");

                LockerBankAggregate::retrieve($lockerBank->id)
                    ->provision($lockerBank, $replyToTopic)
                    ->persist();
            }, 1);

            $mqtt->loop(true);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('An error occurred: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
