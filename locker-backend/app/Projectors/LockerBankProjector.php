<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Enums\FirmwareUpdateStatus;
use App\Models\LockerBank;
use App\StorableEvents\CompartmentStateChangesApplied;
use App\StorableEvents\FirmwareUpdateAccepted;
use App\StorableEvents\FirmwareUpdateFailed;
use App\StorableEvents\FirmwareUpdateRequested;
use App\StorableEvents\LockerConfigAcknowledged;
use App\StorableEvents\LockerConnectionLost;
use App\StorableEvents\LockerConnectionRestored;
use App\StorableEvents\LockerProvisioningReset;
use App\StorableEvents\LockerProvisioningTokenIssued;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class LockerBankProjector extends Projector
{
    public function onCompartmentStateChangesApplied(CompartmentStateChangesApplied $event): void
    {
        $this->applyLastCompartmentStateChangeAt($event->lockerBankUuid, $event->changesObservedAtIso8601);
    }

    private function applyLastCompartmentStateChangeAt(string $lockerBankUuid, string $timestampIso8601): void
    {
        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $ts = Carbon::parse($timestampIso8601);

        $lockerBank->forceFill([
            'last_compartment_state_change_at' => $ts,
        ])->save();
    }

    public function onLockerWasProvisioned(LockerWasProvisioned $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);

        if ($lockerBank) {
            $lockerBank->forceFill([
                'provisioned_at' => $event->createdAt() ?? now(),
                'provisioning_token_hmac' => null,
                'provisioning_token_issued_at' => null,
                'provisioning_generation' => $event->provisioningGeneration,
            ])->save();
        }
    }

    public function onLockerProvisioningTokenIssued(LockerProvisioningTokenIssued $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);

        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'provisioning_token_hmac' => $event->provisioningTokenHmac,
            'provisioning_token_issued_at' => Carbon::parse($event->issuedAtIso8601),
            'provisioning_generation' => $event->provisioningGeneration,
        ])->save();
    }

    /**
     * A reset invalidates everything the backend believed about the device.
     *
     * The bank goes offline because the client it was talking to can no longer
     * authenticate, and the configuration state is cleared because a
     * replacement client has acknowledged nothing — leaving the old "clean"
     * hashes would make the bank look configured when the new device has never
     * seen a config. It stays dirty until a fresh apply_config is sent and
     * acknowledged.
     *
     * The following token-issued event restores the new HMAC and generation.
     */
    public function onLockerProvisioningReset(LockerProvisioningReset $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);

        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'provisioning_token_hmac' => null,
            'provisioning_token_issued_at' => null,
            'provisioning_generation' => null,
            'provisioned_at' => null,
            'connection_status' => 'offline',
            'connection_status_changed_at' => Carbon::parse($event->resetAtIso8601),
            'last_heartbeat_at' => null,
            'last_config_sent_at' => null,
            'last_config_sent_hash' => null,
            'last_config_ack_at' => null,
            'last_config_ack_hash' => null,
        ])->save();
    }

    public function onLockerConnectionLost(LockerConnectionLost $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'connection_status' => 'offline',
            'connection_status_changed_at' => $event->detectedAtIso8601,
        ])->save();
    }

    public function onLockerConnectionRestored(LockerConnectionRestored $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $ts = Carbon::parse($event->restoredAtIso8601);

        $lockerBank->forceFill([
            'connection_status' => 'online',
            'connection_status_changed_at' => $ts,
        ])->save();
    }

    /**
     * The update in flight, as ADR-0077 tracks it: one at a time per bank, so a
     * request replaces whatever finished before it.
     */
    public function onFirmwareUpdateRequested(FirmwareUpdateRequested $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $lockerBank->forceFill([
            'firmware_update_status' => FirmwareUpdateStatus::Requested,
            'firmware_update_transaction_id' => $event->commandId,
            'firmware_update_release_id' => $event->releaseId,
            'firmware_update_target_version' => $event->version,
            'firmware_update_error_code' => null,
            'firmware_update_requested_at' => Carbon::parse($event->requestedAtIso8601),
        ])->save();
    }

    /**
     * Only the update this bank is tracking changes its state. A response or
     * event for an older transaction, or one that arrives after the update
     * ended, is history.
     */
    public function onFirmwareUpdateAccepted(FirmwareUpdateAccepted $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank
            || $lockerBank->firmware_update_transaction_id !== $event->transactionId
            || $lockerBank->firmware_update_status !== FirmwareUpdateStatus::Requested) {
            return;
        }

        $lockerBank->forceFill(['firmware_update_status' => FirmwareUpdateStatus::Downloading])->save();
    }

    public function onFirmwareUpdateFailed(FirmwareUpdateFailed $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank
            || $lockerBank->firmware_update_transaction_id !== $event->transactionId
            || ! $lockerBank->firmware_update_status?->isInFlight()) {
            return;
        }

        $lockerBank->forceFill([
            'firmware_update_status' => FirmwareUpdateStatus::Failed,
            'firmware_update_error_code' => $event->errorCode,
        ])->save();
    }

    public function onLockerConfigAcknowledged(LockerConfigAcknowledged $event): void
    {
        $lockerBank = LockerBank::find($event->lockerBankUuid);
        if (! $lockerBank) {
            return;
        }

        $ts = $event->timestamp ? Carbon::parse($event->timestamp) : now();

        $lockerBank->forceFill([
            'last_config_ack_at' => $ts,
            'last_config_ack_hash' => $event->appliedConfigHash,
        ])->save();
    }
}
