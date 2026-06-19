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
        RolePermission::query()->updateOrCreate(
            [
                'role' => $event->role,
                'permission' => $event->permission,
            ],
            [
                'granted_by_user_id' => $event->actorUserId,
                'granted_at' => Carbon::parse($event->grantedAt),
            ]
        );
    }

    public function onRolePermissionRevoked(RolePermissionRevoked $event): void
    {
        RolePermission::query()
            ->where('role', $event->role)
            ->where('permission', $event->permission)
            ->delete();
    }
}
