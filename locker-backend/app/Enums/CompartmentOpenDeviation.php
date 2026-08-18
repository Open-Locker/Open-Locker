<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ways a compartment can fail to behave as an open command expected.
 *
 * Every deviation is surfaced the same way — a red badge on the compartment, a
 * live danger toast, and an email — so no kind is quietly less visible than
 * another.
 */
enum CompartmentOpenDeviation: string
{
    case DoorJammed = 'door_jammed';
    case AlreadyOpen = 'already_open';
    case UncommandedOpen = 'uncommanded_open';

    public function subject(): string
    {
        return match ($this) {
            self::DoorJammed => __('Compartment did not open'),
            self::AlreadyOpen => __('Compartment was already open'),
            self::UncommandedOpen => __('Compartment opened without a command'),
        };
    }

    public function body(string $lockerBankName, int $compartmentNumber): string
    {
        $replacements = ['number' => $compartmentNumber, 'bank' => $lockerBankName];

        return match ($this) {
            self::DoorJammed => __('Compartment :number on :bank was sent an unlock pulse but its door never opened.', $replacements),
            self::AlreadyOpen => __('Compartment :number on :bank was already open when an unlock pulse was sent.', $replacements),
            self::UncommandedOpen => __('Compartment :number on :bank was opened with no matching open command.', $replacements),
        };
    }

    public function consequence(): string
    {
        return match ($this) {
            self::DoorJammed => __('The lock may be jammed or blocked, or the door sensor may have failed.'),
            self::AlreadyOpen => __('The compartment was accessible before it was unlocked. It may have been left ajar, or its latch may not be holding.'),
            self::UncommandedOpen => __('This may indicate a break-in, tampering, a faulty lock, or a failing door sensor.'),
        };
    }
}
