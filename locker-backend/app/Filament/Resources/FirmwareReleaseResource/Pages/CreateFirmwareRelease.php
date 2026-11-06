<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmwareReleaseResource\Pages;

use App\Filament\Resources\FirmwareReleaseResource;
use App\Services\FirmwareReleaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFirmwareRelease extends CreateRecord
{
    protected static string $resource = FirmwareReleaseResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(FirmwareReleaseService::class)->upload(
            version: (string) $data['version'],
            temporaryPath: (string) $data['image'],
            actorUserId: auth()->user()?->id,
        );
    }
}
