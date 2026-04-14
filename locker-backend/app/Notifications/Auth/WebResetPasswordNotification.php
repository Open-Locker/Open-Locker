<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $email) {}

    public function token(): string
    {
        return $this->token;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset Password Notification'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password'), $this->buildWebResetUrl())
            ->line(__('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }

    private function buildWebResetUrl(): string
    {
        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/').'/reset-password';
        $query = http_build_query([
            'token' => $this->token,
            'email' => $this->email,
        ]);

        return sprintf('%s?%s', $baseUrl, $query);
    }
}
