<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupCompartmentAccessGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly string $grantedAt,
        public readonly ?string $expiresAt = null,
        public readonly ?string $notes = null,
    ) {}
}
