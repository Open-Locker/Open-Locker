<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmwareUpdateFailureReason;
use App\Models\LockerBank;
use App\StorableEvents\FirmwareUpdateFailed;
use App\Support\EventSourcing\StoredEventDispatcher;
use Illuminate\Console\Command;

/**
 * Fails a firmware update that has no outcome after {@see TIMEOUT_MINUTES} (ADR-0077).
 *
 * The device cannot report everything: its failure event is sent once and can be
 * lost, and a restart during the download leaves nothing to report. The failure
 * is an event, so the projector moves the status and a later run finds the update
 * no longer in flight. Like the reactors that derive state from device events, it
 * relies on one process at a time (see ADR-0040); the schedule does not overlap.
 */
class ExpireFirmwareUpdates extends Command
{
    /** How long an update may take, from the request to the heartbeat that completes it. */
    public const TIMEOUT_MINUTES = 30;

    public function __construct(private readonly StoredEventDispatcher $storedEventDispatcher)
    {
        parent::__construct();
    }

    /** @var string */
    protected $signature = 'locker:expire-firmware-updates {--dry-run : Do not record events}';

    /** @var string */
    protected $description = 'Fail firmware updates that are still in flight after the timeout.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expiredBefore = now()->subMinutes(self::TIMEOUT_MINUTES);

        $candidates = LockerBank::query()
            ->updateInFlight()
            ->where('firmware_update_requested_at', '<=', $expiredBefore)
            ->get();

        $expired = 0;

        foreach ($candidates as $candidate) {
            // Read again: a heartbeat may have completed the update since the list was made.
            $lockerBank = LockerBank::query()->find($candidate->id);
            $transactionId = $lockerBank?->inFlightTransactionId();
            if ($lockerBank === null || $transactionId === null || $transactionId !== $candidate->firmware_update_transaction_id) {
                continue;
            }

            $expired++;

            if ($dryRun) {
                continue;
            }

            $this->storedEventDispatcher->dispatch(new FirmwareUpdateFailed(
                lockerBankUuid: (string) $lockerBank->id,
                transactionId: $transactionId,
                timestamp: now()->toIso8601String(),
                reason: FirmwareUpdateFailureReason::Timeout->value,
            ));
        }

        $this->info("Expired {$expired} firmware update(s).".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
