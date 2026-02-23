<?php

declare(strict_types=1);

namespace App\Notifications\Terms;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TermsVersionPublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $documentName,
        private readonly int $version,
    ) {}

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
            ->subject(__('New terms version available'))
            ->line(__('A new version of :name has been published.', ['name' => $this->documentName]))
            ->line(__('Version: :version', ['version' => $this->version]))
            ->line(__('Please review and accept the updated terms in the mobile app.'));
    }
}
