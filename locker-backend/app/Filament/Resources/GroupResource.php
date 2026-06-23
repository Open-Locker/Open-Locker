<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
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

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Groups');
    }

    public static function getModelLabel(): string
    {
        return __('Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Groups');
    }

    public static function canAccess(): bool
    {
        // Group management is admin-only; managers manage direct access only (#95).
        // Admin-only is expressed via the admin-exclusive `groups.manage` permission (#48).
        return auth()->user()?->can(Permission::GroupsManage->value) ?? false;
    }

    public static function form(Schema $form): Schema
    {
        // Name/description are set at creation via GroupCreated. v1 defines no
        // rename event, so editing them directly would drift from the event log
        // on replay — keep them read-only on edit. See ADR-0020.
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255)
                ->disabledOn('edit'),
            Forms\Components\Textarea::make('description')
                ->label(__('Description'))
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
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('members_count')
                    ->label(__('Members'))
                    ->counts('members')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_by')
                    ->label(__('Created by'))
                    ->state(fn (Group $record): ?string => $record->createdByUser?->fullName())
                    ->placeholder(__('System'))
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
