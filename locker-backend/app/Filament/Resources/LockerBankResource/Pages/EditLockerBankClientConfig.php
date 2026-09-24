<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\Pages;

use App\Filament\Resources\LockerBankResource;
use App\Models\LockerBank;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditLockerBankClientConfig extends EditRecord
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string $resource = LockerBankResource::class;

    public static function getNavigationLabel(): string
    {
        return __('Client config');
    }

    public function getTitle(): string
    {
        return __('Client config');
    }

    public function form(Schema $schema): Schema
    {
        return LockerBankResource::clientConfigForm($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            LockerBankResource::sendConfigToClientAction(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof LockerBank || ! $record->isConfigDirty()) {
            return;
        }

        Notification::make()
            ->title(__('Client config saved, but not sent yet'))
            ->body(__('Send the configuration so the locker client applies these values.'))
            ->warning()
            ->send();
    }
}
