<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use App\Models\CompartmentOpenRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OpenRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'openRequests';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Open command history');
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
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
                Tables\Columns\TextColumn::make('compartment.number')
                    ->label(__('Compartment'))
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor_display_name')
                    ->label(__('Actor'))
                    ->state(fn (CompartmentOpenRequest $record): ?string => $record->actor?->fullName())
                    ->placeholder(__('Unknown')),
                Tables\Columns\TextColumn::make('authorization_type')
                    ->label(__('Authorization'))
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
                    ->label(__('Requested at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('opened_at')
                    ->label(__('Opened at'))
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label(__('Failed at'))
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('compartment_id')
                    ->label(__('Compartment'))
                    ->relationship('compartment', 'number'),
                Tables\Filters\Filter::make('failed_only')
                    ->label(__('Failed only'))
                    ->query(fn ($query) => $query->where('status', 'failed')),
                Tables\Filters\Filter::make('denied_only')
                    ->label(__('Denied only'))
                    ->query(fn ($query) => $query->where('status', 'denied')),
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
}
