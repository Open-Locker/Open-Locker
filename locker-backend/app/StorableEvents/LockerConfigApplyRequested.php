<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\NotAudited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[NotAudited('Internal dispatch of the configuration to the locker; the outcome is LockerConfigAcknowledged or LockerConfigAckFailed.')]
class LockerConfigApplyRequested extends ShouldBeStored
{
    /**
     * @param  array<int, array<string, int>>  $compartments
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $commandId,
        public readonly string $configHash,
        public readonly int $heartbeatIntervalSeconds,
        public readonly array $compartments,
        public readonly string $adapterType = 'waveshare_modbus',
        public readonly string $feedbackType = 'door_closing',
    ) {}
}
