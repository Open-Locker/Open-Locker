<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MqttHealthCommandTest extends TestCase
{
    private string $key;

    private int $maxAge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = (string) config('mqtt-client.heartbeat.cache_key');
        $this->maxAge = (int) config('mqtt-client.heartbeat.max_age');
    }

    public function test_it_fails_when_no_heartbeat_exists(): void
    {
        Cache::forget($this->key);

        $this->artisan('mqtt:health')->assertExitCode(1);
    }

    public function test_it_succeeds_with_a_fresh_heartbeat(): void
    {
        Cache::put($this->key, time());

        $this->artisan('mqtt:health')->assertExitCode(0);
    }

    public function test_it_fails_when_the_heartbeat_is_stale(): void
    {
        Cache::put($this->key, time() - ($this->maxAge + 5));

        $this->artisan('mqtt:health')->assertExitCode(1);
    }

    public function test_it_recovers_across_repeated_fail_and_heal_cycles(): void
    {
        foreach (range(1, 3) as $_) {
            // Wedged: pulse goes stale -> unhealthy.
            Cache::put($this->key, time() - ($this->maxAge + 5));
            $this->artisan('mqtt:health')->assertExitCode(1);

            // Loop resumes pulsing -> healthy again.
            Cache::put($this->key, time());
            $this->artisan('mqtt:health')->assertExitCode(0);
        }
    }
}
