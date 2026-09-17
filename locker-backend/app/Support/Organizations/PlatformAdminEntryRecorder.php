<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Aggregates\PlatformAdminAccessAggregate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Session;

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

        $sessionKey = "platform-admin-entered:{$organization->id}";

        if (Session::get($sessionKey) === true) {
            return;
        }

        PlatformAdminAccessAggregate::retrieve(
            PlatformAdminAccessAggregate::aggregateUuidFor($user->id, $organization->id)
        )->enter($user->id, $organization->id, now())->persist();

        Session::put($sessionKey, true);
    }
}
