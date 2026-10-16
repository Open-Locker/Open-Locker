<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation needs an organization but none is in context.
 * Fails closed instead of guessing an organization (ADR-0067).
 */
class MissingOrganizationContextException extends RuntimeException
{
    public function __construct(string $message = 'No organization is in context.')
    {
        parent::__construct($message);
    }
}
