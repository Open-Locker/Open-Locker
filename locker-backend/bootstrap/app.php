<?php

use App\Http\Middleware\EnsureVerifiedEmailApi;
use App\Http\Middleware\RequireAcceptedTerms;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Mobile clients authenticate with a Sanctum bearer token, so the
    // private-channel handshake at /broadcasting/auth must use the token
    // guard rather than the default web/session guard.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('api/*')) {
                return null;
            }

            return route('filament.admin.auth.login');
        });
        $middleware->alias([
            'verified.api' => EnsureVerifiedEmailApi::class,
            'terms.accepted' => RequireAcceptedTerms::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => __('Unauthenticated'),
                ], 401);
            }

            return null;
        });
    })->create();
