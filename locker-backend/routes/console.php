<?php

declare(strict_types=1);

use App\Console\Commands\DetectOfflineLockers;
use App\Console\Commands\ExpireFirmwareUpdates;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DetectOfflineLockers::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(ExpireFirmwareUpdates::class)
    ->everyMinute()
    ->withoutOverlapping();
