<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\FirmwareUpdateRefusal;
use RuntimeException;

class FirmwareUpdateRefused extends RuntimeException
{
    public function __construct(public readonly FirmwareUpdateRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
