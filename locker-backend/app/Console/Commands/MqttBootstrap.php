<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MqttUserService;
use Illuminate\Console\Command;

class MqttBootstrap extends Command
{
    /** @var string */
    protected $signature = 'mqtt:bootstrap';

    /** @var string */
    protected $description = 'Ensure required MQTT system users exist (laravel_backend, provisioning_client).';

    public function __construct(private readonly MqttUserService $mqttUserService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Bootstrapping MQTT system users...');

        try {
            $this->mqttUserService->ensureSystemUsers();
            $this->info('Done.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
