<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Access, 'Content note updated')]
class CompartmentContentNoteUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly ?string $note,
        public readonly string $updatedAtIso8601,
    ) {}
}
