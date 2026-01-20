<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerConfigApplyRequested extends ShouldBeStored
{
    /**
     * @param  array<int, array<string, int>>  $compartments
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $commandId,
        public readonly string $configHash,
        public readonly array $compartments,
    ) {}
}
