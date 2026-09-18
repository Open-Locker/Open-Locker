<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Aggregates\PlatformAdminAccessAggregate;
use App\Models\Organization;
use App\Models\User;
use App\StorableEvents\PlatformAdminEnteredOrganization;
use App\Support\EventSourcing\OrganizationStamp;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * A platform admin may enter any organization; the operator gets to see that it
 * happened.
 *
 * Because nobody sees two organizations at once, entering one is an explicit
 * act — which makes it the natural thing to record. Recorded once per session
 * per organization rather than per request, so a page of AJAX calls does not
 * bury the audit log.
 */
class PlatformAdminEntryRecorder
{
    public function recordIfEntering(User $user, Organization $organization): void
    {
        if (! $user->isPlatformAdmin()) {
            return;
        }

        // A platform admin who is also a member is there as a member.
        if ($user->organizations()->whereKey($organization->id)->exists()) {
            return;
        }

        // Deduplicated by time rather than by session: API clients carry a
        // token and no session at all, so a session key would record every
        // single request there and nothing at all here would bury the log.
        $alreadyRecorded = EloquentStoredEvent::query()
            ->where('event_class', PlatformAdminEnteredOrganization::class)
            ->where('meta_data->'.OrganizationStamp::KEY, $organization->id)
            ->where('event_properties->actorUserId', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        app(OrganizationContext::class)->runWithin($organization, function () use ($user, $organization): void {
            PlatformAdminAccessAggregate::retrieve(
                PlatformAdminAccessAggregate::aggregateUuidFor($user->id, $organization->id)
            )->enter($user->id, $organization->id, now())->persist();
        });
    }
}
