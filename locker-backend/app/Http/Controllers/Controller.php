<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

abstract class Controller
{
    /**
     * The authenticated user on a route behind `auth:sanctum`.
     *
     * `$request->user()` is typed nullable because it has no way to know a route
     * is guarded, which forces a null check into every authenticated action.
     * The middleware is the real guarantee; this states it once, and fails
     * loudly rather than silently if a route is ever mounted without it.
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Route requires an authenticated user but none was resolved.');
        }

        return $user;
    }
}
