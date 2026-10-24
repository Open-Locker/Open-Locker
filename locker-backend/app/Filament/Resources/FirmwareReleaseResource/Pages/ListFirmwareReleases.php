<?php

declare(strict_types=1);

namespace App\Filament\Resources\FirmwareReleaseResource\Pages;

use App\Filament\Resources\FirmwareReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFirmwareReleases extends ListRecords
{
    protected static string $resource = FirmwareReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
