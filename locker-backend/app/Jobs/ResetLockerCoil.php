<?php

namespace App\Jobs;

use App\Models\Locker;
use App\Services\LockerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResetLockerCoil implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly Locker $locker) {}

    /**
     * Execute the job.
     */
    public function handle(LockerService $lockerService): void
    {
        $lockerService->resetLockerCoil($this->locker);
    }
}
