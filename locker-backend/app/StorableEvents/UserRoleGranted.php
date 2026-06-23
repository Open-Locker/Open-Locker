<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserRoleGranted extends ShouldBeStored
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $actorUserId,
        public readonly string $grantedAt,
    ) {}
}
