<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CompartmentContentNoteUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $compartmentUuid,
        public readonly int $actorUserId,
        public readonly ?string $note,
        public readonly string $updatedAtIso8601,
    ) {}
}
