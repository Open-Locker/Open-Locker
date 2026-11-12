<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FirmwareUpdateFailureReason;
use App\Enums\FirmwareUpdateStatus;
use App\Models\LockerBank;
use App\StorableEvents\FirmwareUpdateCompleted;
use App\StorableEvents\FirmwareUpdateFailed;
use App\Support\EventSourcing\StoredEventDispatcher;
use Carbon\CarbonInterface;

/**
 * Derives the end of a firmware update from what a heartbeat reports
 * (ADR-0076, ADR-0077). The report it gets has been validated: it is whole
 * and well formed, or it is not given at all.
 *
 * Both outcomes are events. The event moves the status, so the next heartbeat
 * finds the update no longer in flight and records nothing again.
 */
class FirmwareUpdateOutcomeService
{
    public function __construct(private readonly StoredEventDispatcher $storedEventDispatcher) {}

    /**
     * @param  array{firmware_version: string, trial: bool, last_rollback_version?: string|null}  $report
     */
    public function derive(LockerBank $lockerBank, array $report, CarbonInterface $reportedAt): void
    {
        $transactionId = $lockerBank->inFlightTransactionId();
        $target = $lockerBank->firmware_update_target_version;

        if ($transactionId === null || $target === null) {
            return;
        }

        // The target version outside its trial: the only proof that the new image booted and kept itself.
        if ($report['firmware_version'] === $target && $report['trial'] === false) {
            $this->storedEventDispatcher->dispatch(new FirmwareUpdateCompleted(
                lockerBankUuid: (string) $lockerBank->id,
                transactionId: $transactionId,
                version: $target,
                timestamp: $reportedAt->toIso8601String(),
            ));

            return;
        }

        // A rollback counts only after the device accepted the update. Before that, a unit that
        // rolled this version back earlier still reports it, and the device refuses the version
        // itself (UPDATE_VERSION_REJECTED), which is what fails the update then.
        if ($lockerBank->firmware_update_status === FirmwareUpdateStatus::Downloading
            && ($report['last_rollback_version'] ?? null) === $target) {
            $this->storedEventDispatcher->dispatch(new FirmwareUpdateFailed(
                lockerBankUuid: (string) $lockerBank->id,
                transactionId: $transactionId,
                timestamp: $reportedAt->toIso8601String(),
                reason: FirmwareUpdateFailureReason::Rollback->value,
            ));
        }
    }
}
