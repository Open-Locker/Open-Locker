<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\CompartmentContentNoteUpdated;
use Carbon\CarbonInterface;

class CompartmentContentNoteAggregate extends TransactionalAggregateRoot
{
    public function updateNote(
        int $actorUserId,
        string $compartmentUuid,
        ?string $note,
        CarbonInterface $updatedAt,
    ): self {
        $this->recordThat(new CompartmentContentNoteUpdated(
            compartmentUuid: $compartmentUuid,
            actorUserId: $actorUserId,
            note: $note,
            updatedAtIso8601: $updatedAt->toIso8601String(),
        ));

        return $this;
    }
}
