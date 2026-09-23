<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Enums\Role;
use App\Models\UserRole;
use App\StorableEvents\UserRoleGranted;
use App\StorableEvents\UserRoleRevoked;
use App\Support\Organizations\DefaultOrganization;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Builds the user_roles read model. Intentionally NOT queued: role changes must
 * take effect immediately for the next authorization check.
 */
class UserRoleProjector extends Projector
{
    public function onUserRoleGranted(UserRoleGranted $event): void
    {
        UserRole::query()->updateOrCreate(
            [
                'user_id' => $event->userId,
                'role' => $event->role,
                'organization_id' => $this->organizationFor($event->organizationId, $event->role),
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
            ->where('organization_id', $this->organizationFor($event->organizationId, $event->role))
            ->delete();
    }

    /**
     * Events recorded before organizations existed carry none. They are
     * immutable, so replay maps them to the default organization — permanently,
     * and regardless of how many organizations exist by then.
     *
     * platform_admin is the one role that genuinely belongs to no organization.
     */
    private function organizationFor(?string $organizationId, string $role): ?string
    {
        if ($role === Role::PlatformAdmin->value) {
            return null;
        }

        return $organizationId ?? DefaultOrganization::id();
    }
}
