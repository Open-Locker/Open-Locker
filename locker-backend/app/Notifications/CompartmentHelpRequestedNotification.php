<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A user asked for help with a compartment. Replying to the email reaches the
 * user directly, which is usually the fastest way to sort it out.
 */
class CompartmentHelpRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $userName,
        /** Null when the user was deleted before the alert went out. */
        private readonly ?string $userEmail,
        private readonly string $lockerBankName,
        private readonly int $compartmentNumber,
        private readonly string $message,
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
        $mail = (new MailMessage)
            ->subject(__('Help requested for compartment :number on :bank', [
                'number' => $this->compartmentNumber,
                'bank' => $this->lockerBankName,
            ]));

        if ($this->userEmail === null || $this->userEmail === '') {
            return $mail
                ->line(__(':user asked for help with compartment :number on :bank:', [
                    'user' => $this->userName,
                    'number' => $this->compartmentNumber,
                    'bank' => $this->lockerBankName,
                ]))
                ->line('"'.$this->message.'"');
        }

        return $mail
            ->replyTo($this->userEmail, $this->userName)
            ->line(__(':user (:email) asked for help with compartment :number on :bank:', [
                'user' => $this->userName,
                'email' => $this->userEmail,
                'number' => $this->compartmentNumber,
                'bank' => $this->lockerBankName,
            ]))
            ->line('"'.$this->message.'"')
            ->line(__('Reply to this email to answer them directly.'));
    }
}
