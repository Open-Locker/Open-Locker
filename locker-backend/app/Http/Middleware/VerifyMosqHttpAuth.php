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
        $configUser = (string) config('mqtt-client.webhooks.user');
        $configPass = (string) config('mqtt-client.webhooks.pass');

        // Safety check: If no credentials are configured in the backend, deny everything.
        if ($configUser === '' || $configPass === '') {
            Log::critical('Mosquitto Webhook Auth failed: No credentials configured in env (MOSQ_HTTP_USER/PASS).');

            return response()->json(['allow' => false, 'error' => 'Server misconfiguration'], 500);
        }

        $requestUser = (string) $request->getUser();
        $requestPass = (string) $request->getPassword();

        if ($requestUser === '' || $requestPass === '') {
            return response()->json(['allow' => false, 'error' => 'Unauthorized'], 401);
        }

        // Use hash_equals to prevent timing attacks
        if (! hash_equals($configUser, $requestUser) || ! hash_equals($configPass, $requestPass)) {
            Log::warning("Mosquitto Webhook Auth failed: Invalid credentials for user '{$requestUser}'");

            return response()->json(['allow' => false, 'error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
