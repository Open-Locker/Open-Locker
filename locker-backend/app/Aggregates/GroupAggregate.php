<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\GroupArchived;
use App\StorableEvents\GroupCompartmentAccessGranted;
use App\StorableEvents\GroupCompartmentAccessRevoked;
use App\StorableEvents\GroupCreated;
use App\StorableEvents\UserAddedToGroup;
use App\StorableEvents\UserRemovedFromGroup;
use Carbon\CarbonInterface;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * One aggregate per group; the group's UUID is the aggregate UUID (the group is
 * itself the consistency boundary, so no derived UUID is needed).
 */
class GroupAggregate extends AggregateRoot
{
    public function createGroup(
        string $groupUuid,
        string $name,
        ?string $description,
        int $actorUserId,
        CarbonInterface $createdAt,
    ): self {
        $this->recordThat(new GroupCreated(
            groupUuid: $groupUuid,
            name: $name,
            description: $description,
            actorUserId: $actorUserId,
            createdAt: $createdAt->toIso8601String(),
        ));

        return $this;
    }

    public function addUser(
        string $groupUuid,
        int $userId,
        int $actorUserId,
        CarbonInterface $addedAt,
        ?CarbonInterface $expiresAt = null,
    ): self {
        $this->recordThat(new UserAddedToGroup(
            groupUuid: $groupUuid,
            userId: $userId,
            actorUserId: $actorUserId,
            addedAt: $addedAt->toIso8601String(),
            expiresAt: $expiresAt?->toIso8601String(),
        ));

        return $this;
    }

    public function removeUser(
        string $groupUuid,
        int $userId,
        int $actorUserId,
        CarbonInterface $removedAt,
    ): self {
        $this->recordThat(new UserRemovedFromGroup(
            groupUuid: $groupUuid,
            userId: $userId,
            actorUserId: $actorUserId,
            removedAt: $removedAt->toIso8601String(),
        ));

        return $this;
    }

    public function grantCompartmentAccess(
        string $groupUuid,
        string $compartmentUuid,
        int $actorUserId,
        CarbonInterface $grantedAt,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): self {
        $this->recordThat(new GroupCompartmentAccessGranted(
            groupUuid: $groupUuid,
            compartmentUuid: $compartmentUuid,
            actorUserId: $actorUserId,
            grantedAt: $grantedAt->toIso8601String(),
            expiresAt: $expiresAt?->toIso8601String(),
            notes: $notes,
        ));

        return $this;
    }

    public function archive(
        string $groupUuid,
        int $actorUserId,
        CarbonInterface $archivedAt,
    ): self {
        $this->recordThat(new GroupArchived(
            groupUuid: $groupUuid,
            actorUserId: $actorUserId,
            archivedAt: $archivedAt->toIso8601String(),
        ));

        return $this;
    }

    public function revokeCompartmentAccess(
        string $groupUuid,
        string $compartmentUuid,
        int $actorUserId,
        CarbonInterface $revokedAt,
    ): self {
        $this->recordThat(new GroupCompartmentAccessRevoked(
            groupUuid: $groupUuid,
            compartmentUuid: $compartmentUuid,
            actorUserId: $actorUserId,
            revokedAt: $revokedAt->toIso8601String(),
        ));

        return $this;
    }
}
