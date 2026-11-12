<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a locker bank's firmware update stands (ADR-0077).
 *
 * Requested is backend lifecycle: the command is on its way. From Downloading
 * on it mirrors what the device reported. An update ends Failed (a refusal, a
 * failure event, a rollback or a timeout) or Completed, which only a heartbeat
 * reporting the new version outside its trial can record. A new image still on
 * trial is not a status: the update stays in flight until the trial is over.
 */
enum FirmwareUpdateStatus: string
{
    case Requested = 'requested';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The statuses in which a bank is busy with an update and may fetch its image. The one list
     * everything asks: the request guard, the download route, the projector, the heartbeat and the timeout.
     *
     * @return list<self>
     */
    public static function inFlightCases(): array
    {
        return [self::Requested, self::Downloading];
    }

    public function label(): string
    {
        return match ($this) {
            self::Requested => __('Requested'),
            self::Downloading => __('Downloading'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    /** Filament badge colour for this status. */
    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Requested, self::Downloading => 'warning',
        };
    }

    public function isInFlight(): bool
    {
        return in_array($this, self::inFlightCases(), true);
    }
}
