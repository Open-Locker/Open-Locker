<?php

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\LockerBankResource\Pages;
use App\Filament\Resources\LockerBankResource\RelationManagers;
use App\Models\LockerBank;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LockerBankResource extends Resource
{
    protected static ?string $model = LockerBank::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');
    }

    public static function getNavigationLabel(): string
    {
        return __('Locker banks');
    }

    public static function getModelLabel(): string
    {
        return __('Locker bank');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Locker banks');
    }

    public static function canAccess(): bool
    {
        // Locker bank + Modbus technical config is admin-only (#95).
        return auth()->user()?->can(Permission::LockerBankConfigure->value) ?? false;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([

                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('location_description')
                    ->label(__('Location description'))
                    ->maxLength(65535)
                    ->columnSpanFull(),
                TextInput::make('heartbeat_interval_seconds')
                    ->label(__('Heartbeat interval (seconds)'))
                    ->numeric()
                    ->minValue(1)
                    ->default(10)
                    ->helperText(__('Sent to the client via apply_config.')),
                TextInput::make('heartbeat_timeout_seconds')
                    ->label(__('Heartbeat timeout (seconds)'))
                    ->numeric()
                    ->minValue(1)
                    ->default(30)
                    ->helperText(__('Backend marks the locker offline when no heartbeat is received within this timeout.')),
                Placeholder::make('provisioning_token')
                    ->label(__('Provisioning token'))
                    ->content(fn (?LockerBank $record): string => $record !== null ? $record->provisioning_token : '—'),
                Placeholder::make('provisioned_at')
                    ->label(__('Provisioned at'))
                    ->content(fn (?LockerBank $record): string => $record?->provisioned_at?->toDateTimeString() ?? '—'),
                Placeholder::make('config_status')
                    ->label(__('Config status'))
                    ->content(function (?LockerBank $record): string {
                        if (! $record) {
                            return '—';
                        }

                        if ($record->isConfigDirty()) {
                            return __('Dirty (not confirmed by client yet)');
                        }

                        return __('Clean (confirmed by client)');
                    }),
                Placeholder::make('last_config_ack_at')
                    ->label(__('Last config confirmation'))
                    ->content(fn (?LockerBank $record): string => $record?->last_config_ack_at?->toDateTimeString() ?? '—'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()->sortable(),
                TextColumn::make('compartments_count')
                    ->label(__('Compartments'))
                    ->counts('compartments')
                    ->sortable(),
                TextColumn::make('connection_status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (LockerBank $record): string => $record->connection_status ?? 'unknown')
                    ->color(fn (string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __($state))
                    ->sortable(),
                TextColumn::make('config_status')
                    ->label(__('Config'))
                    ->badge()
                    ->state(fn (LockerBank $record): string => $record->isConfigDirty() ? __('Dirty') : __('Clean'))
                    ->color(fn (string $state): string => $state === __('Dirty') ? 'warning' : 'success')
                    ->tooltip(fn (LockerBank $record): string => $record->last_config_ack_at
                        ? __('Last ack: :date', ['date' => $record->last_config_ack_at->toDateTimeString()])
                        : __('No config ack received yet')),
                TextColumn::make('location_description')
                    ->label(__('Location'))
                    ->searchable(),
                TextColumn::make('provisioning_token')
                    ->copyable()
                    ->copyMessage(__('Token copied!'))
                    ->label(__('Provisioning Token')),
                TextColumn::make('provisioned_at')
                    ->label(__('Provisioned at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_heartbeat_at')
                    ->label(__('Last status update'))
                    ->since()
                    ->tooltip(fn ($record) => optional($record->last_heartbeat_at)?->toDateTimeString())
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CompartmentsRelationManager::class,
            RelationManagers\OpenRequestsRelationManager::class,
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
