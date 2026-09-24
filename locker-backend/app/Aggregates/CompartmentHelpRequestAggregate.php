<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\CompartmentHelpRequested;
use Carbon\CarbonInterface;

/**
 * One aggregate per help request, keyed by its own UUID like an open command,
 * so help requests never contend with the compartment's other streams.
 */
class CompartmentHelpRequestAggregate extends TransactionalAggregateRoot
{
    public function requestHelp(
        string $helpRequestUuid,
        string $compartmentUuid,
        int $actorUserId,
        string $message,
        CarbonInterface $requestedAt,
    ): self {
        $this->recordThat(new CompartmentHelpRequested(
            helpRequestUuid: $helpRequestUuid,
            compartmentUuid: $compartmentUuid,
            actorUserId: $actorUserId,
            message: $message,
            requestedAtIso8601: $requestedAt->toIso8601String(),
        ));

        return $this;
    }
}
