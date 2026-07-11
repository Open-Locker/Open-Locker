<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\CompartmentAccessAggregate;
use App\Aggregates\CompartmentOpenAggregate;
use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Models\UserGroupCompartmentAccess;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CompartmentAccessService
{
    public function grantAccess(
        User $user,
        Compartment $compartment,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
        ?User $actor = null,
    ): void {
        $actor = $this->ensureCanManageAccess($actor, $user);

        $aggregateUuid = CompartmentAccessAggregate::aggregateUuidFor(
            userId: $user->id,
            compartmentUuid: (string) $compartment->id
        );

        CompartmentAccessAggregate::retrieve($aggregateUuid)
            ->grantAccess(
                userId: $user->id,
                actorUserId: $actor->id,
                compartmentUuid: (string) $compartment->id,
                grantedAt: now(),
                expiresAt: $expiresAt,
                notes: $notes
            )
            ->persist();
    }

    public function revokeAccess(User $user, Compartment $compartment, ?User $actor = null): void
    {
        $actor = $this->ensureCanManageAccess($actor, $user);

        $aggregateUuid = CompartmentAccessAggregate::aggregateUuidFor(
            userId: $user->id,
            compartmentUuid: (string) $compartment->id
        );

        CompartmentAccessAggregate::retrieve($aggregateUuid)
            ->revokeAccess(
                userId: $user->id,
                actorUserId: $actor->id,
                compartmentUuid: (string) $compartment->id,
                revokedAt: now()
            )
            ->persist();
    }

    /**
     * Effective access: direct grant OR group-derived access.
     */
    public function hasActiveAccess(User $user, Compartment $compartment): bool
    {
        return $this->hasActiveDirectAccess($user, $compartment)
            || $this->hasActiveGroupAccess($user, $compartment);
    }

    public function hasActiveDirectAccess(User $user, Compartment $compartment): bool
    {
        return CompartmentAccess::query()
            ->where('user_id', $user->id)
            ->where('compartment_id', $compartment->id)
            ->active()
            ->exists();
    }

    public function hasActiveGroupAccess(User $user, Compartment $compartment): bool
    {
        return UserGroupCompartmentAccess::query()
            ->where('user_id', $user->id)
            ->where('compartment_id', $compartment->id)
            ->active()
            ->exists();
    }

    /**
     * Record an open request and authorization decision via event sourcing.
     * Admins and managers are always authorized.
     *
     * @return array{authorized: bool, command_id: string}
     */
    public function requestOpen(User $user, Compartment $compartment): array
    {
        $commandId = (string) Str::uuid();
        $aggregate = CompartmentOpenAggregate::retrieve($commandId)
            ->requestOpen(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id
            );

        if (! $user->hasVerifiedEmail()) {
            $aggregate->deny(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                reason: 'unverified_email'
            )->persist();

            return [
                'authorized' => false,
                'command_id' => $commandId,
            ];
        }

        if ($user->isAdmin()) {
            $aggregate->authorize(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'admin_override'
            )->persist();

            return [
                'authorized' => true,
                'command_id' => $commandId,
            ];
        }

        // Managers may open any compartment operationally (ADR-0021 / #95),
        // recorded as a distinct authorization type for audit clarity.
        if ($user->can(Permission::CompartmentOpen->value)) {
            $aggregate->authorize(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'manager_override'
            )->persist();

            return [
                'authorized' => true,
                'command_id' => $commandId,
            ];
        }

        // Check direct access first so its authorizationType takes precedence.
        if ($this->hasActiveDirectAccess($user, $compartment)) {
            $aggregate->authorize(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'granted_access'
            )->persist();

            return [
                'authorized' => true,
                'command_id' => $commandId,
            ];
        }

        if ($this->hasActiveGroupAccess($user, $compartment)) {
            $aggregate->authorize(
                commandId: $commandId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'group_access'
            )->persist();

            return [
                'authorized' => true,
                'command_id' => $commandId,
            ];
        }

        $aggregate->deny(
            commandId: $commandId,
            actorUserId: $user->id,
            compartmentUuid: (string) $compartment->id,
            reason: 'missing_active_access'
        )->persist();

        return [
            'authorized' => false,
            'command_id' => $commandId,
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureCanManageAccess(?User $actor, User $target): User
    {
        $resolvedActor = $this->resolveActor($actor);

        throw_unless(
            $resolvedActor instanceof User,
            AuthorizationException::class,
            'You are not allowed to grant or revoke compartment access.'
        );

        throw_unless(
            $resolvedActor->can(Permission::CompartmentAccessManage->value),
            AuthorizationException::class,
            'You are not allowed to grant or revoke compartment access.'
        );

        throw_unless(
            ! $target->isAdmin() || $resolvedActor->can(Permission::RolesManage->value),
            AuthorizationException::class,
            'You are not allowed to grant or revoke compartment access for admin users.'
        );

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
