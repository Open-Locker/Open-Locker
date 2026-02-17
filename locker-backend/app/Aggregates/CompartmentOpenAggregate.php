<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpenRequested;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class CompartmentOpenAggregate extends AggregateRoot
{
    public function requestOpen(string $requestId, int $actorUserId, string $compartmentUuid): self
    {
        $this->recordThat(new CompartmentOpenRequested(
            requestId: $requestId,
            actorUserId: $actorUserId,
            compartmentUuid: $compartmentUuid,
        ));

        return $this;
    }

    public function authorize(
        string $requestId,
        int $actorUserId,
        string $compartmentUuid,
        string $authorizationType,
    ): self {
        $this->recordThat(new CompartmentOpenAuthorized(
            requestId: $requestId,
            actorUserId: $actorUserId,
            compartmentUuid: $compartmentUuid,
            authorizationType: $authorizationType,
        ));

        return $this;
    }

    public function deny(
        string $requestId,
        int $actorUserId,
        string $compartmentUuid,
        string $reason,
    ): self {
        $this->recordThat(new CompartmentOpenDenied(
            requestId: $requestId,
            actorUserId: $actorUserId,
            compartmentUuid: $compartmentUuid,
            reason: $reason,
        ));

        return $this;
    }
}
