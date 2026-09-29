<?php

declare(strict_types=1);

use App\Console\Commands\DetectOfflineLockers;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DetectOfflineLockers::class)
    ->everyMinute()
    ->withoutOverlapping();
