<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\GroupAggregate;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class GroupAccessService
{
    public function createGroup(string $name, ?string $description = null, ?User $actor = null): Group
    {
        $actor = $this->ensureCanManageAccess($actor);

        $groupUuid = (string) Str::uuid();

        GroupAggregate::retrieve($groupUuid)
            ->createGroup(
                groupUuid: $groupUuid,
                name: $name,
                description: $description,
                actorUserId: $actor->id,
                createdAt: now(),
            )
            ->persist();

        // The read model is built by GroupProjector, which may run on a queue.
        // Return an in-memory model with the known id so callers do not depend
        // on the projection having completed yet.
        $group = new Group([
            'id' => $groupUuid,
            'name' => $name,
            'description' => $description,
            'created_by_user_id' => $actor->id,
        ]);
        $group->id = $groupUuid;

        return $group;
    }

    public function addUser(Group $group, User $user, ?CarbonInterface $expiresAt = null, ?User $actor = null): void
    {
        $actor = $this->ensureCanManageAccess($actor);

        GroupAggregate::retrieve((string) $group->id)
            ->addUser(
                groupUuid: (string) $group->id,
                userId: $user->id,
                actorUserId: $actor->id,
                addedAt: now(),
                expiresAt: $expiresAt,
            )
            ->persist();
    }

    public function removeUser(Group $group, User $user, ?User $actor = null): void
    {
        $actor = $this->ensureCanManageAccess($actor);

        GroupAggregate::retrieve((string) $group->id)
            ->removeUser(
                groupUuid: (string) $group->id,
                userId: $user->id,
                actorUserId: $actor->id,
                removedAt: now(),
            )
            ->persist();
    }

    public function grantCompartmentAccess(
        Group $group,
        Compartment $compartment,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
        ?User $actor = null,
    ): void {
        $actor = $this->ensureCanManageAccess($actor);

        GroupAggregate::retrieve((string) $group->id)
            ->grantCompartmentAccess(
                groupUuid: (string) $group->id,
                compartmentUuid: (string) $compartment->id,
                actorUserId: $actor->id,
                grantedAt: now(),
                expiresAt: $expiresAt,
                notes: $notes,
            )
            ->persist();
    }

    public function revokeCompartmentAccess(Group $group, Compartment $compartment, ?User $actor = null): void
    {
        $actor = $this->ensureCanManageAccess($actor);

        GroupAggregate::retrieve((string) $group->id)
            ->revokeCompartmentAccess(
                groupUuid: (string) $group->id,
                compartmentUuid: (string) $compartment->id,
                actorUserId: $actor->id,
                revokedAt: now(),
            )
            ->persist();
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureCanManageAccess(?User $actor): User
    {
        $resolvedActor = $this->resolveActor($actor);
        throw_unless($resolvedActor?->isAdmin(), AuthorizationException::class, 'Only admins can manage groups and group access.');

        return $resolvedActor;
    }

    private function resolveActor(?User $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }

        $authUser = Auth::user();

        return $authUser instanceof User ? $authUser : null;
    }
}
