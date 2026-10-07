<?php

declare(strict_types=1);

namespace App\Notifications\Organizations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An existing account was added to another organization without being asked.
 * This email is how the person finds out, and replying reaches whoever added
 * them.
 */
class AddedToOrganizationNotification extends Notification implements ShouldQueue
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
            ->subject(__('You were added to :organization', ['organization' => $this->organizationName]))
            ->replyTo($this->actorEmail, $this->actorName)
            ->line(__(':actor added you to :organization.', [
                'actor' => $this->actorName,
                'organization' => $this->organizationName,
            ]))
            ->line(__('Sign in with your existing account. You can switch between your organizations in the app.'))
            ->line(__('If you did not expect this, reply to this email to contact them.'));
    }
}
