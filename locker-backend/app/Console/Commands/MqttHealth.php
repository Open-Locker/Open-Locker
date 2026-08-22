<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MqttHealth extends Command
{
    /** @var string */
    protected $signature = 'mqtt:health';

    /** @var string */
    protected $description = 'Healthcheck for the mqtt:listen worker: fails if the liveness heartbeat is stale.';

    public function handle(): int
    {
        $key = (string) config('mqtt-client.heartbeat.cache_key');
        $maxAge = (int) config('mqtt-client.heartbeat.max_age');

        $beat = Cache::get($key);

        if ($beat === null) {
            $this->error('Unhealthy: no MQTT listener heartbeat found.');

            return self::FAILURE;
        }

        $age = time() - (int) $beat;

        if ($age > $maxAge) {
            $this->error("Unhealthy: MQTT listener heartbeat is stale ({$age}s > {$maxAge}s).");

            return self::FAILURE;
        }

        $this->info("Healthy: MQTT listener heartbeat is {$age}s old.");

        return self::SUCCESS;
    }
}
