<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompartmentOpenAuthorized extends ShouldBeStored
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $actorUserId,
        public readonly string $compartmentUuid,
        public readonly string $authorizationType,
    ) {}
}
