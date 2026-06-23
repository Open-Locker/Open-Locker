<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\RolePermission;
use App\StorableEvents\RolePermissionGranted;
use App\StorableEvents\RolePermissionRevoked;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Builds the role_permissions read model. Intentionally NOT queued: permission
 * changes must take effect immediately for the next authorization check (ADR-0021).
 */
class RoleProjector extends Projector
{
    public function onRolePermissionGranted(RolePermissionGranted $event): void
    {
        // (Re)activate the binding, clearing any prior revoke audit.
        RolePermission::query()->updateOrCreate(
            [
                'role' => $event->role,
                'permission' => $event->permission,
            ],
            [
                'granted_by_user_id' => $event->actorUserId,
                'granted_at' => Carbon::parse($event->grantedAt),
                'revoked_by_user_id' => null,
                'revoked_at' => null,
            ]
        );
    }

    public function onRolePermissionRevoked(RolePermissionRevoked $event): void
    {
        // Soft revoke: keep the row for the audit trail (active = revoked_at IS NULL).
        RolePermission::query()
            ->where('role', $event->role)
            ->where('permission', $event->permission)
            ->update([
                'revoked_by_user_id' => $event->actorUserId,
                'revoked_at' => Carbon::parse($event->revokedAt),
            ]);
    }
}
