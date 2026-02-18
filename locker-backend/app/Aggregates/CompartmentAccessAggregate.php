<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\CompartmentAccessGranted;
use App\StorableEvents\CompartmentAccessRevoked;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class CompartmentAccessAggregate extends AggregateRoot
{
    public static function aggregateUuidFor(int $userId, string $compartmentUuid): string
    {
        // Snapshots use UUID-typed aggregate_uuid, so we derive a stable UUIDv5.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "compartment-access:{$userId}:{$compartmentUuid}")->toString();
    }

    public function grantAccess(
        int $userId,
        int $actorUserId,
        string $compartmentUuid,
        CarbonInterface $grantedAt,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): self {
        $this->recordThat(new CompartmentAccessGranted(
            userId: $userId,
            actorUserId: $actorUserId,
            compartmentUuid: $compartmentUuid,
            grantedAt: $grantedAt->toIso8601String(),
            expiresAt: $expiresAt?->toIso8601String(),
            notes: $notes,
        ));

        return $this;
    }

    public function revokeAccess(
        int $userId,
        int $actorUserId,
        string $compartmentUuid,
        CarbonInterface $revokedAt,
    ): self {
        $this->recordThat(new CompartmentAccessRevoked(
            userId: $userId,
            actorUserId: $actorUserId,
            compartmentUuid: $compartmentUuid,
            revokedAt: $revokedAt->toIso8601String(),
        ));

        return $this;
    }
}
