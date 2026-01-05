<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyMosqHttpAuth
{
    /**
     * Ensure requests come from Mosquitto HTTP auth plugin using Secret Token.
     */
    public function handle(Request $request, Closure $next)
    {
        // We use the MOSQ_HTTP_PASS as the shared secret token
        $expectedSecret = (string) config('mqtt-client.webhooks.pass');

        // Safety check
        if ($expectedSecret === '') {
            Log::critical('Mosquitto Webhook Auth failed: No credentials configured in env (MOSQ_HTTP_PASS).');

            return response()->json(['allow' => false, 'error' => 'Server misconfiguration'], 500);
        }

        // 1. Try Query Parameter (?mosq_secret=...)
        $providedSecret = $request->query('mosq_secret');

        // 2. Fallback: Try Basic Auth Password (User is ignored)
        if (! $providedSecret) {
            $providedSecret = $request->getPassword();
        }

        if (! $providedSecret) {
            // Only log warning if it's NOT a health check or internal call
            Log::warning('Mosquitto Auth: No secret provided. URL: '.$request->fullUrl());

            return response()->json(['allow' => false, 'error' => 'Unauthorized - No Secret'], 401);
        }

        // Use hash_equals to prevent timing attacks
        if (! hash_equals($expectedSecret, (string) $providedSecret)) {
            Log::warning('Mosquitto Auth: Invalid secret provided.');

            return response()->json(['allow' => false, 'error' => 'Unauthorized - Invalid Secret'], 401);
        }

        return $next($request);
    }
}
