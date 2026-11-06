<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\RealtimeConnectionService;
use Illuminate\Http\JsonResponse;

class AppInfoController extends Controller
{
    /**
     * Returns information for identifying the application.
     *
     * `realtime` carries the public Reverb connection settings, so a client that
     * switches servers at runtime connects to that server's websocket (ADR-0087).
     * It is null when the server has no public Reverb host or key configured.
     */
    public function identify(RealtimeConnectionService $realtime): JsonResponse
    {
        return response()->json([
            'name' => 'Open-Locker',
            'type' => 'backend',
            'api_version' => 'v1',
            'version' => (string) config('app.version', 'dev'),
            'identifier' => 'open-locker-backend',
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
            'realtime' => $realtime->publicSettings(),
        ]);
    }
}
