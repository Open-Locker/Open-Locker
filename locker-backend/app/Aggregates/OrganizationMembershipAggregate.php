<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\UserAddedToOrganization;
use App\StorableEvents\UserRemovedFromOrganization;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * One aggregate per (organization, user) pair; owns that membership.
 * `actorUserId` is null for system-initiated changes (bootstrap / backfill).
 */
class OrganizationMembershipAggregate extends TransactionalAggregateRoot
{
    private bool $isMember = false;

    public static function aggregateUuidFor(int $organizationId, int $userId): string
    {
        // Snapshots use UUID-typed aggregate_uuid, so we derive a stable UUIDv5.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "organization-membership:{$organizationId}:{$userId}")->toString();
    }

    public function addMember(int $organizationId, int $userId, ?int $actorUserId, CarbonInterface $joinedAt): self
    {
        if ($this->isMember) {
            return $this; // idempotent: already a member
        }

        $this->recordThat(new UserAddedToOrganization(
            organizationId: $organizationId,
            userId: $userId,
            actorUserId: $actorUserId,
            joinedAt: $joinedAt->toIso8601String(),
        ));

        return $this;
    }

    public function removeMember(int $organizationId, int $userId, ?int $actorUserId, CarbonInterface $removedAt): self
    {
        if (! $this->isMember) {
            return $this; // idempotent: not a member
        }

        $this->recordThat(new UserRemovedFromOrganization(
            organizationId: $organizationId,
            userId: $userId,
            actorUserId: $actorUserId,
            removedAt: $removedAt->toIso8601String(),
        ));

        return $this;
    }

    protected function applyUserAddedToOrganization(UserAddedToOrganization $event): void
    {
        $this->isMember = true;
    }

    protected function applyUserRemovedFromOrganization(UserRemovedFromOrganization $event): void
    {
        $this->isMember = false;
    }
}
