<?php

declare(strict_types=1);

namespace App\Enums;

enum CompartmentDoorState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Unknown = 'unknown';
}
