<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GroupCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $groupUuid,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $actorUserId,
        public readonly string $createdAt,
    ) {}
}
