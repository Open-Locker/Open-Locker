<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompartmentOpenRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $actorUserId,
        public readonly string $compartmentUuid,
    ) {}
}
