<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\UserRoleGranted;
use App\StorableEvents\UserRoleRevoked;
use App\Support\Organizations\DefaultOrganization;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;

/**
 * One aggregate per user; owns that user's role assignments.
 * `actorUserId` is null for system-initiated grants (bootstrap / backfill).
 */
class UserRoleAggregate extends TransactionalAggregateRoot
{
    /** @var array<string, true> currently-held (organization, role) pairs, rebuilt from events */
    private array $roles = [];

    public static function aggregateUuidFor(int $userId): string
    {
        // Snapshots use UUID-typed aggregate_uuid, so we derive a stable UUIDv5.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "user-role:{$userId}")->toString();
    }

    public function grantRole(
        int $userId,
        string $role,
        ?int $actorUserId,
        CarbonInterface $grantedAt,
        ?string $organizationId = null,
    ): self {
        if (isset($this->roles[self::heldKey($organizationId, $role)])) {
            return $this; // idempotent: already granted in this organization
        }

        $this->recordThat(new UserRoleGranted(
            userId: $userId,
            role: $role,
            actorUserId: $actorUserId,
            grantedAt: $grantedAt->toIso8601String(),
            organizationId: $organizationId,
        ));

        return $this;
    }

    public function revokeRole(
        int $userId,
        string $role,
        ?int $actorUserId,
        CarbonInterface $revokedAt,
        ?string $organizationId = null,
    ): self {
        if (! isset($this->roles[self::heldKey($organizationId, $role)])) {
            return $this; // idempotent: not held in this organization
        }

        $this->recordThat(new UserRoleRevoked(
            userId: $userId,
            role: $role,
            actorUserId: $actorUserId,
            revokedAt: $revokedAt->toIso8601String(),
            organizationId: $organizationId,
        ));

        return $this;
    }

    /**
     * Events recorded before organizations existed carry none, and replay must
     * still answer for them: they belong to the default organization, and the
     * held-set has to agree with the projector or replay turns idempotent.
     */
    private static function heldKey(?string $organizationId, string $role): string
    {
        return ($organizationId ?? DefaultOrganization::id() ?? 'none').'|'.$role;
    }

    protected function applyUserRoleGranted(UserRoleGranted $event): void
    {
        $this->roles[self::heldKey($event->organizationId, $event->role)] = true;
    }

    protected function applyUserRoleRevoked(UserRoleRevoked $event): void
    {
        unset($this->roles[self::heldKey($event->organizationId, $event->role)]);
    }
}
