<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Access, 'Open requested')]
class CompartmentOpenRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $commandId,
        public readonly int $actorUserId,
        public readonly string $compartmentUuid,
    ) {}
}
