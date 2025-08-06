<?php

namespace App\Filament\Resources\LockerBankResource\Pages;

use App\Filament\Resources\LockerBankResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLockerBanks extends ListRecords
{
    protected static string $resource = LockerBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
