<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CompartmentOpenRequestResource\Pages;
use App\Models\CompartmentOpenRequest;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompartmentOpenRequestResource extends Resource
{
    protected static ?string $model = CompartmentOpenRequest::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('Open Command History');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('command_id')
                    ->label(__('Command ID'))
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'opened' => 'success',
                        'failed', 'denied' => 'danger',
                        'sent', 'accepted', 'requested' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state ? __($state) : '')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor_display_name')
                    ->label(__('Actor'))
                    ->state(fn (CompartmentOpenRequest $record): ?string => $record->actor?->fullName())
                    ->placeholder(__('Unknown')),
                Tables\Columns\TextColumn::make('compartment.lockerBank.name')
                    ->label(__('Locker bank'))
                    ->placeholder(__('Unknown'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('compartment.number')
                    ->label(__('Compartment'))
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('authorization_type')
                    ->label(__('Authorization'))
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_code')
                    ->label(__('Error code'))
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label(__('Error message'))
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('denied_reason')
                    ->label(__('Denied reason'))
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('requested_at')
                    ->label(__('Requested at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->label(__('Opened at'))
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label(__('Failed at'))
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('failed_only')
                    ->label(__('Failed only'))
                    ->query(fn (Builder $query): Builder => $query->where('status', 'failed')),
                Tables\Filters\Filter::make('denied_only')
                    ->label(__('Denied only'))
                    ->query(fn (Builder $query): Builder => $query->where('status', 'denied')),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'requested' => __('requested'),
                        'accepted' => __('accepted'),
                        'sent' => __('sent'),
                        'opened' => __('opened'),
                        'failed' => __('failed'),
                        'denied' => __('denied'),
                    ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'actor',
                'compartment.lockerBank',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompartmentOpenRequests::route('/'),
        ];
    }
}
