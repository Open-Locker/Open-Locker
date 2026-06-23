<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\StorableEvents\RolePermissionGranted;
use App\StorableEvents\RolePermissionRevoked;
use Carbon\CarbonInterface;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * One aggregate per role; owns that role's permission bindings (ADR-0021).
 * `actorUserId` is null for system-initiated grants (default-binding seed).
 */
class RoleAggregate extends AggregateRoot
{
    /** @var array<string, true> currently-bound permissions, rebuilt from events */
    private array $permissions = [];

    public static function aggregateUuidFor(string $role): string
    {
        // Snapshots use UUID-typed aggregate_uuid, so we derive a stable UUIDv5.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "role:{$role}")->toString();
    }

    public function grantPermission(string $role, string $permission, ?int $actorUserId, CarbonInterface $grantedAt): self
    {
        if (isset($this->permissions[$permission])) {
            return $this; // idempotent
        }

        $this->recordThat(new RolePermissionGranted(
            role: $role,
            permission: $permission,
            actorUserId: $actorUserId,
            grantedAt: $grantedAt->toIso8601String(),
        ));

        return $this;
    }

    public function revokePermission(string $role, string $permission, ?int $actorUserId, CarbonInterface $revokedAt): self
    {
        if (! isset($this->permissions[$permission])) {
            return $this; // idempotent
        }

        $this->recordThat(new RolePermissionRevoked(
            role: $role,
            permission: $permission,
            actorUserId: $actorUserId,
            revokedAt: $revokedAt->toIso8601String(),
        ));

        return $this;
    }

    protected function applyRolePermissionGranted(RolePermissionGranted $event): void
    {
        $this->permissions[$event->permission] = true;
    }

    protected function applyRolePermissionRevoked(RolePermissionRevoked $event): void
    {
        unset($this->permissions[$event->permission]);
    }
}
