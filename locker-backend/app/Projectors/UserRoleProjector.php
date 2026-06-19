<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\UserRole;
use App\StorableEvents\UserRoleGranted;
use App\StorableEvents\UserRoleRevoked;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Builds the user_roles read model. Intentionally NOT queued: role changes must
 * take effect immediately for the next authorization check (ADR-0021).
 */
class UserRoleProjector extends Projector
{
    public function onUserRoleGranted(UserRoleGranted $event): void
    {
        UserRole::query()->updateOrCreate(
            [
                'user_id' => $event->userId,
                'role' => $event->role,
            ],
            [
                'granted_by_user_id' => $event->actorUserId,
                'granted_at' => Carbon::parse($event->grantedAt),
            ]
        );
    }

    public function onUserRoleRevoked(UserRoleRevoked $event): void
    {
        UserRole::query()
            ->where('user_id', $event->userId)
            ->where('role', $event->role)
            ->delete();
    }
}
