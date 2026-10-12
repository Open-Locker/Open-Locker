<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\NotAudited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[NotAudited('Internal dispatch of the open command to the locker; the request itself is CompartmentOpenRequested.')]
class CompartmentOpeningRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $compartmentUuid,
        public readonly int $compartmentNumber,
        public readonly string $commandId,
    ) {}
}
