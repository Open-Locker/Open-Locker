<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AppInfoController extends Controller
{
    /**
     * Returns information for identifying the application.
     */
    public function identify(): JsonResponse
    {
        return response()->json([
            'name' => 'Open-Locker',
            'type' => 'backend',
            'api_version' => 'v1',
            'version' => (string) config('app.version', 'dev'),
            'identifier' => 'open-locker-backend',
            'environment' => app()->environment(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
