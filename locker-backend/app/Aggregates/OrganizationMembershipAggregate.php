<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\UserJoinedOrganization;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * One aggregate per (user, organization) membership.
 */
class OrganizationMembershipAggregate extends TransactionalAggregateRoot
{
    public static function aggregateUuidFor(int $userId, string $organizationId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "organization-membership:{$userId}:{$organizationId}")->toString();
    }

    public function join(
        int $userId,
        string $organizationId,
        ?int $actorUserId,
        bool $existingAccount,
        CarbonInterface $joinedAt,
    ): self {
        $this->recordThat(new UserJoinedOrganization(
            userId: $userId,
            organizationId: $organizationId,
            actorUserId: $actorUserId,
            existingAccount: $existingAccount,
            joinedAt: $joinedAt->toIso8601String(),
        ));

        return $this;
    }
}
