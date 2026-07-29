<?php

namespace App\Filament\Resources\LockerBankResource\Pages;

use App\Filament\Resources\LockerBankResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLockerBank extends EditRecord
{
    protected static string $resource = LockerBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Recovering a device usually starts on this page — the token and
            // the provisioning state are shown right here — so the reset
            // belongs next to them, not only back on the list.
            LockerBankResource::resetProvisioningAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
