<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HybridResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $email) {}

    public function token(): string
    {
        return $this->token;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appResetUrl = $this->buildAppResetUrl();
        $webResetUrl = $this->buildWebResetUrl();

        return (new MailMessage)
            ->subject(__('Reset Password Notification'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset Password in App'), $appResetUrl)
            ->line(__('If the app link does not work, use this browser link: :url', ['url' => $webResetUrl]))
            ->line(__('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('If you did not request a password reset, no further action is required.'));
    }

    private function buildAppResetUrl(): string
    {
        $scheme = (string) config('auth-links.mobile_scheme', 'open-locker://');
        $path = trim((string) config('auth-links.mobile_reset_path', 'reset-password'), '/');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $this->email,
        ]);

        $prefix = str_ends_with($scheme, '://') ? $scheme : rtrim($scheme, '/').'/';

        return sprintf('%s%s?%s', $prefix, $path, $query);
    }

    private function buildWebResetUrl(): string
    {
        $baseUrl = rtrim((string) config('auth-links.web_reset_url'), '/');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $this->email,
        ]);

        return sprintf('%s?%s', $baseUrl, $query);
    }
}
