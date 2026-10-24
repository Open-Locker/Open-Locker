<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use LogicException;

/**
 * A signed firmware image that lockers can be updated to (ADR-0077). The
 * backend stores the image as uploaded; it holds no signing key.
 *
 * @property-read string $id
 * @property-read string $version
 * @property-read string $file_path
 * @property-read string $sha256
 * @property-read int $size_bytes
 * @property-read bool $invalid
 * @property-read int|null $uploaded_by_user_id
 * @property-read CarbonImmutable $created_at
 */
class FirmwareRelease extends Model
{
    /** @use HasFactory<\Database\Factories\FirmwareReleaseFactory> */
    use HasFactory;

    use HasUuids;

    /** How long a download link works (ADR-0077). */
    public const DOWNLOAD_LINK_MINUTES = 15;

    /** The firmware accepts a URL of at most this many characters (ADR-0076). */
    public const DOWNLOAD_URL_MAX_LENGTH = 256;

    /**
     * The largest image an OTA slot holds: 0x1D0000 bytes, the size of ota_0 and ota_1 in
     * locker-firmware/partitions.csv. A larger image could never be written.
     */
    public const MAX_IMAGE_BYTES = 0x1D0000;

    /** The version field of the image and of the heartbeat holds at most this many characters (ADR-0076). */
    public const VERSION_MAX_LENGTH = 31;

    /** Semantic versioning without build metadata, as in the AsyncAPI schema. */
    private const VERSION_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?$/';

    protected $fillable = [
        'version',
        'file_path',
        'sha256',
        'size_bytes',
        'invalid',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'invalid' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(static function (self $release): void {
            if ($release->isInUse()) {
                throw new LogicException('A firmware release that a locker bank is updating to cannot be deleted.');
            }
        });

        static::saving(static function (self $release): void {
            if (! self::isValidVersion((string) $release->version)) {
                throw new InvalidArgumentException("'{$release->version}' is not a valid firmware version (semantic versioning, at most ".self::VERSION_MAX_LENGTH.' characters).');
            }
        });
    }

    /** What the firmware accepts as a download link: https, and at most 256 characters (ADR-0076). */
    public static function isUsableDownloadUrl(string $url): bool
    {
        return str_starts_with($url, 'https://') && strlen($url) <= self::DOWNLOAD_URL_MAX_LENGTH;
    }

    /** Whether a locker bank is still fetching or installing this release (an update in flight). */
    public function isInUse(): bool
    {
        return LockerBank::query()
            ->updateInFlight()
            ->where('firmware_update_release_id', $this->id)
            ->exists();
    }

    public static function isValidVersion(string $version): bool
    {
        return strlen($version) <= self::VERSION_MAX_LENGTH && preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /**
     * The link a locker downloads this image from: signed, naming this release
     * and that locker bank, valid for {@see DOWNLOAD_LINK_MINUTES}. Its query
     * string is a credential; it must never be stored or logged (ADR-0077).
     */
    public function signedDownloadUrl(string $lockerBankUuid, ?DateTimeInterface $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'firmware.download',
            $expiresAt ?? now()->addMinutes(self::DOWNLOAD_LINK_MINUTES),
            ['release' => $this->id, 'lockerBank' => $lockerBankUuid],
        );
    }
}
