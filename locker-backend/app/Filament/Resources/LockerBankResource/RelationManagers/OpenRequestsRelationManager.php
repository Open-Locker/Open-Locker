<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OpenRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'openRequests';

    protected static ?string $title = 'Open command history';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
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
                Tables\Columns\TextColumn::make('compartment.number')
                    ->label('Compartment')
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor.name')
                    ->label('Actor')
                    ->placeholder('Unknown')
                    ->searchable(),
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
                Tables\Columns\TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('compartment_id')
                    ->label('Compartment')
                    ->relationship('compartment', 'number'),
                Tables\Filters\Filter::make('failed_only')
                    ->label('Failed only')
                    ->query(fn ($query) => $query->where('status', 'failed')),
                Tables\Filters\Filter::make('denied_only')
                    ->label('Denied only')
                    ->query(fn ($query) => $query->where('status', 'denied')),
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
}
