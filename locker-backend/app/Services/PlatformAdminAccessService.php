<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\PlatformAdminAccessAggregate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;

class PlatformAdminAccessService
{
    public function recordEntry(User $user, Organization $organization): void
    {
        app(OrganizationContext::class)->runWithin($organization, function () use ($user, $organization): void {
            PlatformAdminAccessAggregate::retrieve(
                PlatformAdminAccessAggregate::aggregateUuidFor($user->id, $organization->id)
            )->enter($user->id, $organization->id, now())->persist();
        });
    }
}
