<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a role or user mutation would leave the installation without an
 * administrator. Aborts the surrounding transaction so nothing is committed.
 */
class LastAdminException extends RuntimeException
{
    public function __construct(string $message = 'The last administrator cannot be removed.')
    {
        parent::__construct($message);
    }
}
