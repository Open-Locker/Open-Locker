<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\UserRoleGranted;
use App\StorableEvents\UserRoleRevoked;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * One aggregate per user; owns that user's role assignments (ADR-0021).
 * `actorUserId` is null for system-initiated grants (bootstrap / backfill).
 */
class UserRoleAggregate extends AggregateRoot
{
    /** @var array<string, true> currently-held roles, rebuilt from events */
    private array $roles = [];

    public static function aggregateUuidFor(int $userId): string
    {
        // Snapshots use UUID-typed aggregate_uuid, so we derive a stable UUIDv5.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "user-role:{$userId}")->toString();
    }

    public function grantRole(int $userId, string $role, ?int $actorUserId, CarbonInterface $grantedAt): self
    {
        if (isset($this->roles[$role])) {
            return $this; // idempotent: already granted
        }

        $this->recordThat(new UserRoleGranted(
            userId: $userId,
            role: $role,
            actorUserId: $actorUserId,
            grantedAt: $grantedAt->toIso8601String(),
        ));

        return $this;
    }

    public function revokeRole(int $userId, string $role, ?int $actorUserId, CarbonInterface $revokedAt): self
    {
        if (! isset($this->roles[$role])) {
            return $this; // idempotent: not held
        }

        $this->recordThat(new UserRoleRevoked(
            userId: $userId,
            role: $role,
            actorUserId: $actorUserId,
            revokedAt: $revokedAt->toIso8601String(),
        ));

        return $this;
    }

    protected function applyUserRoleGranted(UserRoleGranted $event): void
    {
        $this->roles[$event->role] = true;
    }

    protected function applyUserRoleRevoked(UserRoleRevoked $event): void
    {
        unset($this->roles[$event->role]);
    }
}
