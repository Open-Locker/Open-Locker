<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a firmware update cannot be requested for a locker bank (ADR-0077).
 */
enum FirmwareUpdateRefusal: string
{
    case NotProvisioned = 'not_provisioned';
    case NotCapable = 'not_capable';
    case UpdateInFlight = 'update_in_flight';
    case ReleaseInvalid = 'release_invalid';
    /** The link would not start with https:// or would not fit the 256 characters the firmware accepts. */
    case DownloadUrlUnusable = 'download_url_unusable';

    public function message(): string
    {
        return match ($this) {
            self::NotProvisioned => 'The locker bank is not provisioned.',
            self::NotCapable => 'The locker bank did not report the firmware_update capability.',
            self::UpdateInFlight => 'A firmware update of this locker bank is already in progress.',
            self::ReleaseInvalid => 'The firmware release is marked invalid.',
            self::DownloadUrlUnusable => 'The download link would not be an https URL of at most 256 characters; check the public URL of the backend.',
        };
    }
}
