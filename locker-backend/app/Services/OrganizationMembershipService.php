<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\OrganizationMembershipAggregate;
use App\Models\Organization;
use App\Models\User;

class OrganizationMembershipService
{
    /**
     * Idempotent: adding an existing member records nothing.
     */
    public function addMember(Organization $organization, User $user, ?int $actorUserId = null): void
    {
        OrganizationMembershipAggregate::retrieve(OrganizationMembershipAggregate::aggregateUuidFor($organization->id, $user->id))
            ->addMember($organization->id, $user->id, $actorUserId, now())
            ->persist();
    }
}
