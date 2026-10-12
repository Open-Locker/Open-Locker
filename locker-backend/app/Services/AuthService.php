<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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

    /**
     * Reset a password with a broker token. Completing the reset proves the
     * user owns the address, so it also verifies the email.
     *
     * @param  array{email: string, password: string, password_confirmation: string, token: string}  $credentials
     * @return string The password broker status
     */
    public function resetPassword(array $credentials): string
    {
        return Password::reset($credentials, function (User $user) use ($credentials): void {
            $this->setPassword($user, $credentials['password']);
            $this->markEmailAsVerified($user);

            event(new PasswordReset($user));
        });
    }

    public function changePassword(User $user, string $password): void
    {
        $this->setPassword($user, $password);
    }

    /**
     * A changed email address needs verifying again, so it clears the
     * verification and sends a new link.
     */
    public function updateProfile(User $user, string $firstName, string $lastName, string $email): User
    {
        $user->fill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);

        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user->fresh() ?? $user;
    }

    public function markEmailAsVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }

    private function setPassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();
    }
}
