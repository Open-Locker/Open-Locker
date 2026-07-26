<?php

declare(strict_types=1);

namespace App\Notifications\Security;

use App\Enums\CompartmentOpenDeviation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A compartment did not behave as an open command expected (ADR-0031).
 *
 * One notification for every deviation — jammed, already open, or opened with no
 * command behind it — so operators learn about all of them the same way rather
 * than having to know which kinds are surfaced where.
 *
 * Mail only. The live in-panel toast is broadcast separately, and the persistent
 * in-panel record is the audit log (ADR-0026).
 */
class CompartmentOpenDeviationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CompartmentOpenDeviation $deviation,
        private readonly string $lockerBankName,
        private readonly int $compartmentNumber,
        private readonly ?int $millisecondsSinceLastRelayFire = null,
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
        $message = (new MailMessage)
            ->error()
            ->subject($this->deviation->subject())
            ->line($this->deviation->body($this->lockerBankName, $this->compartmentNumber));

        foreach ($this->contextLines() as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function contextLines(): array
    {
        if ($this->deviation !== CompartmentOpenDeviation::UncommandedOpen) {
            return [$this->deviation->consequence()];
        }

        $age = $this->millisecondsSinceLastRelayFire === null
            ? __('No unlock pulse has been sent to this compartment.')
            : __('The last unlock pulse on this compartment was :minutes minutes earlier.', [
                'minutes' => (int) round($this->millisecondsSinceLastRelayFire / 60000),
            ]);

        return [$age, $this->deviation->consequence()];
    }
}
