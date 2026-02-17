<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\CompartmentAccessGranted;
use App\StorableEvents\CompartmentAccessRevoked;
use Carbon\CarbonInterface;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class CompartmentAccessAggregate extends AggregateRoot
{
    public static function aggregateUuidFor(int $userId, string $compartmentUuid): string
    {
        return "compartment-access:{$userId}:{$compartmentUuid}";
    }

    public function grantAccess(
        int $userId,
        string $compartmentUuid,
        CarbonInterface $grantedAt,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): self {
        $this->recordThat(new CompartmentAccessGranted(
            userId: $userId,
            compartmentUuid: $compartmentUuid,
            grantedAt: $grantedAt->toIso8601String(),
            expiresAt: $expiresAt?->toIso8601String(),
            notes: $notes,
        ));

        return $this;
    }

    public function revokeAccess(
        int $userId,
        string $compartmentUuid,
        CarbonInterface $revokedAt,
    ): self {
        $this->recordThat(new CompartmentAccessRevoked(
            userId: $userId,
            compartmentUuid: $compartmentUuid,
            revokedAt: $revokedAt->toIso8601String(),
        ));

        return $this;
    }
}
