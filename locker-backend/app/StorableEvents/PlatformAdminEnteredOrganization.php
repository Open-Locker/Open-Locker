<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Recorded when someone who administers the installation enters an operator's
 * organization without belonging to it. The operator can see, afterwards, that
 * the host was inside their tenancy and when.
 */
class PlatformAdminEnteredOrganization extends ShouldBeStored
{
    public function __construct(
        public readonly int $actorUserId,
        public readonly string $organizationId,
        public readonly string $enteredAt,
    ) {}
}
