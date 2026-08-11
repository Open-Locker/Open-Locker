<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupArchived extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly int $actorUserId,
        public readonly string $archivedAt,
    ) {}
}
