<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FirmwareRelease;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<FirmwareRelease>
 */
class FirmwareReleaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = '0.'.$this->faker->unique()->numberBetween(1, 99999).'.0';

        return [
            'version' => $version,
            'file_path' => "firmware/{$version}.bin",
            'sha256' => hash('sha256', ''),
            'size_bytes' => 0,
        ];
    }

    /**
     * Stores `$bytes` on the default disk under the release's path, and records its size and hash.
     */
    public function withImage(string $bytes = 'IMAGE'): static
    {
        return $this->afterMaking(static function (FirmwareRelease $release) use ($bytes): void {
            Storage::put($release->file_path, $bytes);
            $release->sha256 = hash('sha256', $bytes);
            $release->size_bytes = strlen($bytes);
        });
    }
}
