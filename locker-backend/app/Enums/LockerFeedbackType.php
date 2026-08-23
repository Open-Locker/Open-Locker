<?php

declare(strict_types=1);

namespace App\Enums;

enum LockerFeedbackType: string
{
    case DoorClosing = 'door_closing';
    case DoorOpening = 'door_opening';

    public function label(): string
    {
        return match ($this) {
            self::DoorClosing => __('Active signal means door closing'),
            self::DoorOpening => __('Active signal means door opening'),
        };
    }
}
