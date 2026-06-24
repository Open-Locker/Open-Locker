<?php

declare(strict_types=1);

namespace App\Enums;

enum CompartmentDoorState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Door open'),
            self::Closed => __('Door closed'),
            self::Unknown => __('Door unknown'),
        };
    }
}
