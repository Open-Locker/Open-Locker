<?php

namespace App\Exceptions;

use Exception;

class InvalidAddressException extends Exception
{
    public function __construct(
        public int $address,
        public int $unitId,
        string $message = 'Ungültige Modbus-Adresse',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $fullMessage = "{$message} (Adresse: {$address}, Unit ID: {$unitId})";
        parent::__construct($fullMessage, $code, $previous);
    }
}
