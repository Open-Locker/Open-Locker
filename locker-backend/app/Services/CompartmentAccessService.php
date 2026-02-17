<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\CompartmentAccessAggregate;
use App\Aggregates\CompartmentOpenAggregate;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class CompartmentAccessService
{
    public function grantAccess(
        User $user,
        Compartment $compartment,
        ?CarbonInterface $expiresAt = null,
        ?string $notes = null,
    ): void {
        $aggregateUuid = CompartmentAccessAggregate::aggregateUuidFor(
            userId: $user->id,
            compartmentUuid: (string) $compartment->id
        );

        CompartmentAccessAggregate::retrieve($aggregateUuid)
            ->grantAccess(
                userId: $user->id,
                compartmentUuid: (string) $compartment->id,
                grantedAt: now(),
                expiresAt: $expiresAt,
                notes: $notes
            )
            ->persist();
    }

    public function revokeAccess(User $user, Compartment $compartment): void
    {
        $aggregateUuid = CompartmentAccessAggregate::aggregateUuidFor(
            userId: $user->id,
            compartmentUuid: (string) $compartment->id
        );

        CompartmentAccessAggregate::retrieve($aggregateUuid)
            ->revokeAccess(
                userId: $user->id,
                compartmentUuid: (string) $compartment->id,
                revokedAt: now()
            )
            ->persist();
    }

    public function hasActiveAccess(User $user, Compartment $compartment): bool
    {
        return CompartmentAccess::query()
            ->where('user_id', $user->id)
            ->where('compartment_id', $compartment->id)
            ->active()
            ->exists();
    }

    /**
     * Record an open request and authorization decision via event sourcing.
     * Admins are always authorized.
     */
    public function requestOpen(User $user, Compartment $compartment): bool
    {
        $requestId = (string) Str::uuid();
        $aggregate = CompartmentOpenAggregate::retrieve($requestId)
            ->requestOpen(
                requestId: $requestId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id
            );

        if ($user->isAdmin()) {
            $aggregate->authorize(
                requestId: $requestId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'admin_override'
            )->persist();

            return true;
        }

        if ($this->hasActiveAccess($user, $compartment)) {
            $aggregate->authorize(
                requestId: $requestId,
                actorUserId: $user->id,
                compartmentUuid: (string) $compartment->id,
                authorizationType: 'granted_access'
            )->persist();

            return true;
        }

        $aggregate->deny(
            requestId: $requestId,
            actorUserId: $user->id,
            compartmentUuid: (string) $compartment->id,
            reason: 'missing_active_access'
        )->persist();

        return false;
    }
}
