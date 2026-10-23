<?php

declare(strict_types=1);

namespace App\Support\EventSourcing;

use App\Models\Organization;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Puts the acting organization on every stored event, as metadata.
 *
 * Metadata rather than a constructor argument on each event class: the
 * organization is context about where a thing happened, not part of what
 * happened, and stamping it centrally means a new event class cannot forget it.
 *
 * Reading it back is where the historical case is answered. Events recorded
 * before organizations existed carry no stamp and are immutable, so they read as
 * the default organization — permanently, however many organizations exist by
 * the time they are replayed.
 */
final class OrganizationStamp
{
    public const KEY = 'organization_id';

    public static function apply(ShouldBeStored $event): void
    {
        $metaData = $event->metaData();

        // Never overwritten: an event replayed or recorded with an explicit
        // organization keeps it, whatever happens to be in context now.
        if (array_key_exists(self::KEY, $metaData)) {
            return;
        }

        $organizationId = app(OrganizationContext::class)->currentId();

        if ($organizationId === null) {
            return;
        }

        $event->setMetaData($metaData + [self::KEY => $organizationId]);
    }

    /**
     * Handle an event inside the organization it was recorded in.
     *
     * Queued handlers have no request behind them, so anything they record
     * would otherwise store unstamped and read back as the default
     * organization — which means one operator's jammed door alerting another
     * operator's managers. The causing event already knows where it happened;
     * derived events inherit it from there rather than from ambient state.
     */
    public static function runWithin(ShouldBeStored $event, callable $handler): mixed
    {
        $organizationId = self::from($event);

        $organization = $organizationId === null
            ? null
            : Organization::query()->find($organizationId);

        return app(OrganizationContext::class)->runWithin($organization, $handler);
    }

    public static function from(ShouldBeStored $event): ?string
    {
        $organizationId = $event->metaData()[self::KEY] ?? null;

        return is_string($organizationId) ? $organizationId : DefaultOrganization::id();
    }
}
