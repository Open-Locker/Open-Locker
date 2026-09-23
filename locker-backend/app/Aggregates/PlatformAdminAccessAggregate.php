<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\PlatformAdminEnteredOrganization;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * One aggregate per (platform admin, organization) pair.
 */
class PlatformAdminAccessAggregate extends TransactionalAggregateRoot
{
    public static function aggregateUuidFor(int $userId, string $organizationId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "platform-admin-access:{$userId}:{$organizationId}")->toString();
    }

    public function enter(int $actorUserId, string $organizationId, CarbonInterface $enteredAt): self
    {
        $this->recordThat(new PlatformAdminEnteredOrganization(
            actorUserId: $actorUserId,
            organizationId: $organizationId,
            enteredAt: $enteredAt->toIso8601String(),
        ));

        return $this;
    }
}
