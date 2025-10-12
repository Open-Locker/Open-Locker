<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyVmqWebhookAuth
{
    /**
     * Ensure requests come from VerneMQ using Basic Auth credentials.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = (string) config('mqtt-client.webhooks.user');
        $pass = (string) config('mqtt-client.webhooks.pass');

        $provided = $request->getUser() !== null || $request->getPassword() !== null;
        if (! $provided || $request->getUser() !== $user || $request->getPassword() !== $pass) {
            return response()->json(['result' => 'error', 'message' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
