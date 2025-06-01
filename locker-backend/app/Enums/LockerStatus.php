<?php

namespace App\Enums;

enum LockerStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Unreachable = 'unreachable';

    case Unknown = 'unknown';

}
