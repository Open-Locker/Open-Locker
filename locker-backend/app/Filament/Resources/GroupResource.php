<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Group;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    public static function form(Schema $form): Schema
    {
        // Name/description are set at creation via GroupCreated. v1 defines no
        // rename event, so editing them directly would drift from the event log
        // on replay — keep them read-only on edit. See ADR-0020.
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->disabledOn('edit'),
            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->maxLength(2000)
                ->disabledOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_by')
                    ->label('Created by')
                    ->state(fn (Group $record): ?string => $record->createdByUser?->fullName())
                    ->placeholder('System')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ]);
        // No delete action (v1): groups cannot be deleted. See ADR-0020 / #106.
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            CompartmentAccessesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroups::route('/'),
            'create' => Pages\CreateGroup::route('/create'),
            'edit' => Pages\EditGroup::route('/{record}/edit'),
        ];
    }
}
