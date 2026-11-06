<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Carries the release, never the download link: the link is a credential, and
 * an event is permanent. The publisher makes a fresh signed link when it sends
 * the command (ADR-0077).
 */
#[Audited(AuditCategory::Devices, 'Firmware update requested')]
class FirmwareUpdateRequested extends ShouldBeStored
{
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $commandId,
        public readonly string $releaseId,
        public readonly string $version,
        public readonly bool $allowDowngrade,
        public readonly ?int $actorUserId,
        public readonly string $requestedAtIso8601,
    ) {}
}
