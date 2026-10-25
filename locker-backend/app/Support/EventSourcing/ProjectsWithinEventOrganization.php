<?php

declare(strict_types=1);

namespace App\Support\EventSourcing;

use Spatie\EventSourcing\StoredEvents\StoredEvent;

/**
 * Projects each event inside the organization it was recorded in.
 *
 * Live, the recording request or MQTT handler already put that organization in
 * context. A replay has none, and a projector that reads organization-scoped
 * models then finds no rows to update and inserts rows the organization
 * constraint refuses. Taking the organization from the event makes both the
 * same.
 */
trait ProjectsWithinEventOrganization
{
    public function handle(StoredEvent $storedEvent): void
    {
        $event = $storedEvent->event;

        if ($event === null) {
            parent::handle($storedEvent);

            return;
        }

        OrganizationStamp::runWithin($event, fn () => parent::handle($storedEvent));
    }
}
