<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\User;
use App\StorableEvents\UserAddedToOrganization;
use App\StorableEvents\UserRemovedFromOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Builds the organization_user read model. Intentionally NOT queued:
 * membership decides authorization for the next request.
 *
 * ADR-0067: never reads the organization context, so a replay rebuilds the
 * same rows as the live run.
 */
class OrganizationMembershipProjector extends Projector
{
    public function onUserAddedToOrganization(UserAddedToOrganization $event): void
    {
        // Deleting a user cascades their memberships away; replaying their
        // events must not bring the rows back or violate the foreign key.
        if (! User::query()->whereKey($event->userId)->exists()) {
            return;
        }

        DB::table('organization_user')->updateOrInsert(
            [
                'organization_id' => $event->organizationId,
                'user_id' => $event->userId,
            ],
            [
                'added_by_user_id' => $event->actorUserId,
                'joined_at' => Carbon::parse($event->joinedAt),
            ]
        );
    }

    public function onUserRemovedFromOrganization(UserRemovedFromOrganization $event): void
    {
        DB::table('organization_user')
            ->where('organization_id', $event->organizationId)
            ->where('user_id', $event->userId)
            ->delete();
    }

    public function resetState(?string $aggregateUuid = null): void
    {
        if ($aggregateUuid === null) {
            DB::table('organization_user')->delete();
        }
    }
}
