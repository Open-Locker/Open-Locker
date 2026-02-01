<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class DeviceEventReceived extends ShouldBeStored
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank sending the event
     * @param  string  $event  Event name
     * @param  string|null  $eventId  Optional id for deduplication
     * @param  string|null  $timestamp  ISO8601 timestamp provided by the client (optional)
     * @param  array<string,mixed>  $data  Event payload
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $event,
        public readonly ?string $eventId = null,
        public readonly ?string $timestamp = null,
        public readonly array $data = [],
    ) {}
}
