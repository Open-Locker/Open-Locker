<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Resources\ApiErrorResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedEmailApi
{
    /**
     * Ensure the authenticated user has a verified email address.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return (new ApiErrorResource([
                'status' => false,
                'message' => __('Please verify your email address before opening compartments'),
            ]))->response()->setStatusCode(403);
        }

        return $next($request);
    }
}
