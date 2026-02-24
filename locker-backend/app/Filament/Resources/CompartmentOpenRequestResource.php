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

    protected static ?string $navigationLabel = 'Open Command History';

    protected static bool $shouldRegisterNavigation = false;

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
                    ->label('Command ID')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'opened' => 'success',
                        'failed', 'denied' => 'danger',
                        'sent', 'accepted', 'requested' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Actor')
                    ->placeholder('Unknown')
                    ->searchable(),
                Tables\Columns\TextColumn::make('compartment.lockerBank.name')
                    ->label('Locker bank')
                    ->placeholder('Unknown')
                    ->searchable(),
                Tables\Columns\TextColumn::make('compartment.number')
                    ->label('Compartment')
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('authorization_type')
                    ->label('Authorization')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_code')
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('denied_reason')
                    ->limit(50)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('failed_only')
                    ->label('Failed only')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'failed')),
                Tables\Filters\Filter::make('denied_only')
                    ->label('Denied only')
                    ->query(fn (Builder $query): Builder => $query->where('status', 'denied')),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'requested' => 'requested',
                        'accepted' => 'accepted',
                        'sent' => 'sent',
                        'opened' => 'opened',
                        'failed' => 'failed',
                        'denied' => 'denied',
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
