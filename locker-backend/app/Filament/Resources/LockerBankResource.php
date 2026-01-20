<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LockerBankResource\Pages;
use App\Filament\Resources\LockerBankResource\RelationManagers;
use App\Models\LockerBank;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LockerBankResource extends Resource
{
    protected static ?string $model = LockerBank::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('location_description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                TextInput::make('heartbeat_interval_seconds')
                    ->label('Heartbeat interval (seconds)')
                    ->numeric()
                    ->minValue(1)
                    ->default(5)
                    ->helperText('Sent to the client via apply_config.'),
                TextInput::make('heartbeat_timeout_seconds')
                    ->label('Heartbeat timeout (seconds)')
                    ->numeric()
                    ->minValue(1)
                    ->default(30)
                    ->helperText('Backend marks the locker offline when no heartbeat is received within this timeout.'),
                Placeholder::make('config_status')
                    ->label('Config status')
                    ->content(function (?LockerBank $record): string {
                        if (! $record) {
                            return '—';
                        }

                        if ($record->isConfigDirty()) {
                            return 'Dirty (not confirmed by client yet)';
                        }

                        return 'Clean (confirmed by client)';
                    }),
                Placeholder::make('last_config_ack_at')
                    ->label('Last config confirmation')
                    ->content(fn (?LockerBank $record): string => $record?->last_config_ack_at?->toDateTimeString() ?? '—'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->searchable()->sortable(),
                TextColumn::make('connection_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (LockerBank $record): string => $record->connection_status ?? 'unknown')
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('config_status')
                    ->label('Config')
                    ->badge()
                    ->state(fn (LockerBank $record): string => $record->isConfigDirty() ? 'Dirty' : 'Clean')
                    ->color(fn (string $state): string => $state === 'Dirty' ? 'warning' : 'success')
                    ->tooltip(fn (LockerBank $record): ?string => $record->last_config_ack_at
                        ? 'Last ack: '.$record->last_config_ack_at->toDateTimeString()
                        : 'No config ack received yet'),
                TextColumn::make('location_description')
                    ->searchable(),
                TextColumn::make('provisioning_token')
                    ->copyable() // does not work without SSL
                    ->copyMessage('Token copied!')
                    ->label('Provisioning Token'),
                TextColumn::make('provisioned_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_heartbeat_at')
                    ->label('Last status update')
                    ->since()
                    ->tooltip(fn ($record) => optional($record->last_heartbeat_at)?->toDateTimeString())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CompartmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLockerBanks::route('/'),
            'create' => Pages\CreateLockerBank::route('/create'),
            'edit' => Pages\EditLockerBank::route('/{record}/edit'),
        ];
    }
}
