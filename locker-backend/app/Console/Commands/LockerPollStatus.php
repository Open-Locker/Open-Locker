<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LockerService;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

class LockerPollStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locker:poll-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Continuously polls the status of all lockers.';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(LockerService $lockerService): int
    {
        $this->info('Starting locker status polling...');

        while (true) {
            try {
                $lockerService->pollAndUpdateAllLockerStatuses($this->output);
            } catch (Throwable $e) {
                $this->error('A critical error occurred in the polling cycle: '.$e->getMessage());
                Sleep::sleep(5);
            }
            Sleep::sleep(0.5);
        }

        return Command::SUCCESS;
    }
}
