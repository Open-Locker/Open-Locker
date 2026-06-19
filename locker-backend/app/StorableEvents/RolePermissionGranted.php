<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RolePermissionGranted extends ShouldBeStored
{
    public function __construct(
        public readonly string $role,
        public readonly string $permission,
        public readonly ?int $actorUserId,
        public readonly string $grantedAt,
    ) {}
}
