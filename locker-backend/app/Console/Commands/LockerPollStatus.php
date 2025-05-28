<?php

declare(strict_types=1);

namespace App\Console\Commands;

// App\Enums\LockerStatus wird hier nicht mehr direkt benötigt, da die Logik im Service ist
// use App\Enums\LockerStatus;
// App\Models\Locker wird hier nicht mehr direkt benötigt
// use App\Models\Locker;
use App\Services\LockerService;
use Illuminate\Console\Command;
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
                // Die gesamte Logik ist jetzt im Service.
                // Wir übergeben $this->output, damit der Service in die Konsole loggen kann.
                $lockerService->pollAndUpdateAllLockerStatuses($this->output);

            } catch (Throwable $e) {
                $this->error('A critical error occurred in the polling cycle: '.$e->getMessage());
                // Log::channel('stderr')->error('Outer polling loop critical error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
                // Bei sehr kritischen Fehlern (z.B. DB nicht erreichbar) kann eine längere Pause sinnvoll sein
                sleep(5);
            }
            usleep(500000);
        }
        // Diese Sektion ist aufgrund der Endlosschleife nicht erreichbar.
        // return Command::SUCCESS;
    }
}
