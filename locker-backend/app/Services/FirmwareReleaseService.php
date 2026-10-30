<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FirmwareRelease;
use ArrayObject;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Uploading, invalidating and deleting firmware releases (ADR-0077).
 */
class FirmwareReleaseService
{
    /** The first byte of every ESP32 application image (esp_image_header_t magic). */
    private const ESP_IMAGE_MAGIC = "\xE9";

    /**
     * Turns an uploaded file into a release. The hash and size come from the stored bytes. The
     * temporary upload is removed on every path.
     *
     * @throws ValidationException keyed like the Filament form (data.image, data.version)
     */
    public function upload(string $version, string $temporaryPath, ?int $actorUserId): FirmwareRelease
    {
        try {
            $bytes = Storage::get($temporaryPath);

            if ($bytes === null || $bytes === '' || strlen($bytes) > FirmwareRelease::MAX_IMAGE_BYTES) {
                throw ValidationException::withMessages(['data.image' => __('The firmware image is missing or larger than an OTA slot.')]);
            }

            if ($bytes[0] !== self::ESP_IMAGE_MAGIC) {
                throw ValidationException::withMessages(['data.image' => __('The file is not an ESP32 firmware image.')]);
            }

            return $this->store($version, $bytes, $actorUserId);
        } finally {
            Storage::delete($temporaryPath);
        }
    }

    public function markInvalid(FirmwareRelease $release): void
    {
        $release->forceFill(['invalid' => true])->save();
    }

    /**
     * @throws LogicException when a locker bank is still updating to this release
     */
    public function delete(FirmwareRelease $release): void
    {
        if ($release->isInUse()) {
            throw new LogicException('A firmware release that a locker bank is updating to cannot be deleted.');
        }

        $release->delete();
        Storage::delete($release->file_path);
    }

    private function store(string $version, string $bytes, ?int $actorUserId): FirmwareRelease
    {
        $path = "firmware/{$version}.bin";
        $written = new ArrayObject(['file' => false]);

        try {
            // The row goes in first: its unique version decides a race, so the file of an existing
            // release is never touched by the loser.
            return DB::transaction(function () use ($version, $bytes, $path, $actorUserId, $written): FirmwareRelease {
                $release = FirmwareRelease::query()->create([
                    'version' => $version,
                    'file_path' => $path,
                    'sha256' => hash('sha256', $bytes),
                    'size_bytes' => strlen($bytes),
                    'uploaded_by_user_id' => $actorUserId,
                ]);

                // Never overwrite: a leftover file of that name is somebody's image.
                if (Storage::exists($path)) {
                    throw $this->versionTaken();
                }

                Storage::put($path, $bytes);
                $written['file'] = true;

                return $release;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->versionTaken();
        } catch (Throwable $e) {
            if ($written['file']) {
                Storage::delete($path);
            }

            throw $e;
        }
    }

    private function versionTaken(): ValidationException
    {
        return ValidationException::withMessages(['data.version' => __('This firmware version already exists.')]);
    }
}
