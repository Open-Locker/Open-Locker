<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use App\Enums\LockerAdapterType;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBankClientConfig;
use App\Models\LockerBank;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CompartmentMappingRelationManager extends RelationManager
{
    protected static string $relationship = 'compartments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Compartment mapping');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === EditLockerBankClientConfig::class;
    }

    private function lockerBank(): LockerBank
    {
        $lockerBank = $this->getOwnerRecord();
        if (! $lockerBank instanceof LockerBank) {
            throw new \LogicException('Compartments must belong to a locker bank.');
        }

        return $lockerBank;
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->label(__('Number'))
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(1)
                    ->helperText(__('1-based compartment number (logical ID used by MQTT commands).')),

                Forms\Components\TextInput::make('slave_id')
                    ->label(__('Slave ID'))
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(1)
                    ->maxValue(fn (): int => $this->lockerBank()->adapter_type === LockerAdapterType::Rs485LockBoard ? 31 : 255)
                    ->helperText(fn (): string => $this->lockerBank()->adapter_type === LockerAdapterType::Rs485LockBoard
                        ? __('RS485 board address set by the DIP switches (1-31).')
                        : __('Modbus slave ID of the IO board (1-255).')),

                Forms\Components\TextInput::make('address')
                    ->label(__('Address'))
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(0)
                    ->maxValue(LockerBank::MAX_WIRE_CHANNEL_ADDRESS)
                    ->helperText(__('0-based channel address on the given slave (0–254 wire-encodable range). Used for both coil and input.')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->sortable()
                    ->label(__('Compartment'))
                    ->prefix('#'),

                Tables\Columns\TextInputColumn::make('slave_id')
                    ->label(__('Slave ID'))
                    ->rules(fn (): array => [
                        'nullable',
                        'integer',
                        'min:1',
                        'max:'.($this->lockerBank()->adapter_type === LockerAdapterType::Rs485LockBoard ? 31 : 255),
                    ])
                    ->tooltip(fn (): string => $this->lockerBank()->adapter_type === LockerAdapterType::Rs485LockBoard
                        ? __('RS485 board address set by the DIP switches (1-31).')
                        : __('Modbus slave ID (1-255).')),

                Tables\Columns\TextInputColumn::make('address')
                    ->label(__('Address'))
                    ->rules(fn (): array => [
                        'nullable',
                        'integer',
                        'min:0',
                        'max:'.LockerBank::MAX_WIRE_CHANNEL_ADDRESS,
                    ])
                    ->tooltip(__('0-based channel address (0–254 wire-encodable range). Used for both coil and input.')),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
