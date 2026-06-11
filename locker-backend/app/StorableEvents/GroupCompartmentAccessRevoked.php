<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupCompartmentAccessRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly string $revokedAt,
    ) {}
}
