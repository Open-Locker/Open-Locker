<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompartmentAccessRevoked extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly int $actorUserId,
        public readonly string $compartmentUuid,
        public readonly string $revokedAt,
    ) {}
}
