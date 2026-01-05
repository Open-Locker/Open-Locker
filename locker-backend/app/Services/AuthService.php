<?php

namespace App\Services;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Password;

class AuthService
{
    /**
     * Send a password reset link to a given email.
     *
     * @param string $email
     * @return string The status of the password reset attempt
     */
    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

}
