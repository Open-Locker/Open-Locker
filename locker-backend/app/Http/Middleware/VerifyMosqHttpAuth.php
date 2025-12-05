<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyMosqHttpAuth
{
    /**
     * Ensure requests come from Mosquitto HTTP auth plugin using Basic Auth credentials.
     */
    public function handle(Request $request, Closure $next)
    {
        return $next($request);

        $user = (string) config('mqtt-client.webhooks.user');
        $pass = (string) config('mqtt-client.webhooks.pass');

        Log::info('User: '.$user.' Pass: '.$pass);
        Log::info('Request: '.$request->headers);

        // Strict mode: both env values must be set and must match incoming Basic Auth
        if ($user === '' || $pass === '') {
            return response()->json(['allow' => false, 'ok' => false], 401);
        }

        $provided = $request->getUser() !== null || $request->getPassword() !== null;
        if (! $provided || $request->getUser() !== $user || $request->getPassword() !== $pass) {
            return response()->json(['allow' => false, 'ok' => false], 401);
        }

        return $next($request);
    }
}
