<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a locker bank's firmware update stands (ADR-0077).
 *
 * Requested is backend lifecycle: the command is on its way. From Downloading
 * on it mirrors what the device reported. An update ends in Failed here; the
 * end of a successful one is a heartbeat reporting the new version.
 */
enum FirmwareUpdateStatus: string
{
    case Requested = 'requested';
    case Downloading = 'downloading';
    case Failed = 'failed';

    /** The bank is busy with this update, and may fetch its image. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Requested, self::Downloading => true,
            self::Failed => false,
        };
    }
}
