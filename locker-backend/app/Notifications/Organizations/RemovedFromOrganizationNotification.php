<?php

declare(strict_types=1);

namespace App\Notifications\Organizations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The mirror of AddedToOrganizationNotification: an account was removed from
 * one organization and keeps the others. Replying reaches whoever removed it.
 */
class RemovedFromOrganizationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationName,
        private readonly string $actorName,
        private readonly string $actorEmail,
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
            ->subject(__('You were removed from :organization', ['organization' => $this->organizationName]))
            ->replyTo($this->actorEmail, $this->actorName)
            ->line(__(':actor removed you from :organization.', [
                'actor' => $this->actorName,
                'organization' => $this->organizationName,
            ]))
            ->line(__('Your account and any other organizations you belong to are not affected.'))
            ->line(__('If you think this is a mistake, reply to this email to contact them.'));
    }
}
