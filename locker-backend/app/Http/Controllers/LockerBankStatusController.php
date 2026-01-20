<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LockerBank;
use Illuminate\Http\JsonResponse;

class LockerBankStatusController
{
    public function __invoke(LockerBank $lockerBank): JsonResponse
    {
        return response()->json([
            'id' => (string) $lockerBank->id,
            'connection_status' => (string) ($lockerBank->connection_status ?? 'unknown'),
            'connection_status_changed_at' => $lockerBank->connection_status_changed_at?->toIso8601String(),
            'last_heartbeat_at' => $lockerBank->last_heartbeat_at?->toIso8601String(),
            'heartbeat_interval_seconds' => (int) $lockerBank->heartbeat_interval_seconds,
            'heartbeat_timeout_seconds' => (int) $lockerBank->heartbeat_timeout_seconds,
        ]);
    }
}
