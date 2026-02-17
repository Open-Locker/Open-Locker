<?php

namespace App\Services;

use Illuminate\Support\Facades\Password;

class AuthService
{
    /**
     * Send a password reset link to a given email.
     *
     * @return string The status of the password reset attempt
     */
    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }
}
