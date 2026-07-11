<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\UserGroupCompartmentAccess;
use App\StorableEvents\GroupCompartmentAccessGranted;
use App\StorableEvents\GroupCompartmentAccessRevoked;
use App\StorableEvents\GroupCreated;
use App\StorableEvents\UserAddedToGroup;
use App\StorableEvents\UserRemovedFromGroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class GroupProjector extends Projector
{
    public function onGroupCreated(GroupCreated $event): void
    {
        Group::query()->updateOrCreate(
            ['id' => $event->groupUuid],
            [
                'name' => $event->name,
                'description' => $event->description,
                'created_by_user_id' => $event->actorUserId,
            ]
        );
    }

    public function onUserAddedToGroup(UserAddedToGroup $event): void
    {
        $expiresAt = $event->expiresAt ? Date::parse($event->expiresAt) : null;

        DB::table('group_user')->updateOrInsert(
            ['group_id' => $event->groupUuid, 'user_id' => $event->userId],
            [
                'added_at' => Date::parse($event->addedAt),
                'added_by_user_id' => $event->actorUserId,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'removed_by_user_id' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->recomputeGroup($event->groupUuid);
    }

    public function onUserRemovedFromGroup(UserRemovedFromGroup $event): void
    {
        DB::table('group_user')
            ->where('group_id', $event->groupUuid)
            ->where('user_id', $event->userId)
            ->update([
                'revoked_at' => Date::parse($event->removedAt),
                'removed_by_user_id' => $event->actorUserId,
                'updated_at' => now(),
            ]);

        $this->recomputeGroup($event->groupUuid);
    }

    public function onGroupCompartmentAccessGranted(GroupCompartmentAccessGranted $event): void
    {
        GroupCompartmentAccess::query()->updateOrCreate(
            [
                'group_id' => $event->groupUuid,
                'compartment_id' => $event->compartmentUuid,
            ],
            [
                'granted_at' => Date::parse($event->grantedAt),
                'granted_by_user_id' => $event->actorUserId,
                'expires_at' => $event->expiresAt ? Date::parse($event->expiresAt) : null,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'notes' => $event->notes,
            ]
        );

        $this->recomputeGroup($event->groupUuid);
    }

    public function onGroupCompartmentAccessRevoked(GroupCompartmentAccessRevoked $event): void
    {
        GroupCompartmentAccess::query()
            ->where('group_id', $event->groupUuid)
            ->where('compartment_id', $event->compartmentUuid)
            ->update([
                'revoked_at' => Date::parse($event->revokedAt),
                'revoked_by_user_id' => $event->actorUserId,
            ]);

        $this->recomputeGroup($event->groupUuid);
    }

    /**
     * Rebuild the derived effective rows touched by one group. Scoped per group
     * (not a full-table rebuild): we look only at the (user, compartment) pairs
     * this group could contribute now, plus the pairs it contributed before, and
     * recompute each from scratch across ALL groups so the union stays correct
     * and the unique(user_id, compartment_id) constraint always holds.
     */
    private function recomputeGroup(string $groupUuid): void
    {
        DB::transaction(function () use ($groupUuid): void {
            $now = now();

            // Active members of this group, with their membership expiry.
            $members = DB::table('group_user')
                ->where('group_id', $groupUuid)
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->pluck('user_id')
                ->all();

            // Active compartment grants of this group.
            $compartmentIds = GroupCompartmentAccess::query()
                ->where('group_id', $groupUuid)
                ->active()
                ->pluck('compartment_id')
                ->all();

            // Pairs this group could contribute now.
            $touched = [];
            foreach ($members as $userId) {
                foreach ($compartmentIds as $compartmentId) {
                    $touched["{$userId}|{$compartmentId}"] = [(int) $userId, (string) $compartmentId];
                }
            }

            // Plus pairs this group contributed before (so removals are cleaned up).
            UserGroupCompartmentAccess::query()
                ->where('group_id', $groupUuid)
                ->get(['user_id', 'compartment_id'])
                ->each(function (UserGroupCompartmentAccess $row) use (&$touched): void {
                    $touched["{$row->user_id}|{$row->compartment_id}"] = [(int) $row->user_id, (string) $row->compartment_id];
                });

            foreach ($touched as [$userId, $compartmentId]) {
                $this->recomputePair($userId, $compartmentId, $now);
            }
        });
    }

    /**
     * Recompute one (user, compartment) effective row from every group that
     * grants it. Access if any group grants it; effective expiry is the most
     * permissive (null = never expires wins, otherwise the latest expiry).
     */
    private function recomputePair(int $userId, string $compartmentId, CarbonInterface $now): void
    {
        // Per contributing group, the path expiry = earliest of membership and grant expiry.
        $paths = DB::table('group_user as gu')
            ->join('group_compartment_accesses as gca', 'gca.group_id', '=', 'gu.group_id')
            ->where('gu.user_id', $userId)
            ->where('gca.compartment_id', $compartmentId)
            ->whereNull('gu.revoked_at')
            ->where(fn ($q) => $q->whereNull('gu.expires_at')->orWhere('gu.expires_at', '>', $now))
            ->whereNull('gca.revoked_at')
            ->where(fn ($q) => $q->whereNull('gca.expires_at')->orWhere('gca.expires_at', '>', $now))
            ->get(['gu.group_id', 'gu.expires_at as member_expires_at', 'gca.expires_at as grant_expires_at']);

        if ($paths->isEmpty()) {
            UserGroupCompartmentAccess::query()
                ->where('user_id', $userId)
                ->where('compartment_id', $compartmentId)
                ->delete();

            return;
        }

        $effectiveExpiry = null;       // null beats any concrete date (most permissive)
        $unlimited = false;
        $winningGroupId = null;

        foreach ($paths as $path) {
            $pathExpiry = $this->earliest(
                $path->member_expires_at ? Date::parse($path->member_expires_at) : null,
                $path->grant_expires_at ? Date::parse($path->grant_expires_at) : null,
            );

            if ($pathExpiry === null) {
                $unlimited = true;
                $winningGroupId = $path->group_id;
                break;
            }

            if ($effectiveExpiry === null || $pathExpiry->greaterThan($effectiveExpiry)) {
                $effectiveExpiry = $pathExpiry;
                $winningGroupId = $path->group_id;
            }
        }

        UserGroupCompartmentAccess::query()->updateOrCreate(
            ['user_id' => $userId, 'compartment_id' => $compartmentId],
            [
                'group_id' => $winningGroupId,
                'expires_at' => $unlimited ? null : $effectiveExpiry,
            ]
        );
    }

    private function earliest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return $a->lessThan($b) ? $a : $b;
    }
}
