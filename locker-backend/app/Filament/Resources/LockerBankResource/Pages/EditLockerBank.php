<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\Pages;

use App\Filament\Concerns\InteractsWithOneTimeProvisioningToken;
use App\Filament\Resources\LockerBankResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLockerBank extends EditRecord
{
    use InteractsWithOneTimeProvisioningToken;

    protected static string $resource = LockerBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LockerBankResource::restartProvisioningAction(),
            LockerBankResource::showProvisioningTokenAction()
                ->extraAttributes(['class' => 'hidden']),
            Actions\DeleteAction::make(),
        ];
    }
}
