<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CommandResponseReceived extends ShouldBeStored
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank sending the response
     * @param  string  $transactionId  Correlation id for the command/response
     * @param  string|null  $action  Command action name
     * @param  string|null  $result  success|error (etc.)
     * @param  string|null  $timestamp  ISO8601 timestamp provided by the client (optional)
     * @param  string|null  $errorCode  Optional error code
     * @param  string|null  $message  Optional human readable message
     * @param  array<string,mixed>  $data  Optional extra data
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $transactionId,
        public readonly ?string $action = null,
        public readonly ?string $result = null,
        public readonly ?string $timestamp = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}
}
