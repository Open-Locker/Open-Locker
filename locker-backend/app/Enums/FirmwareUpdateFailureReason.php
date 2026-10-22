<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the backend itself ended a firmware update as failed (ADR-0077). The
 * device's own codes (UPDATE_*, ADR-0076) stay in `errorCode`; these are what
 * the backend concludes when the device could not say.
 */
enum FirmwareUpdateFailureReason: string
{
    /** The unit booted the new image, did not confirm it, and went back (a heartbeat says so). */
    case Rollback = 'rollback';

    /** No outcome arrived in time: a lost failure event, or a restart during the download. */
    case Timeout = 'timeout';
}
