<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\GroupResource\Pages;
use App\Filament\Resources\GroupResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\GroupResource\RelationManagers\MembersRelationManager;
use App\Models\Group;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Access management');
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
            ->filters([
                // Archived groups are event-sourced retired groups (ADR-0020 / #106),
                // not deleted — hidden from the list by default, filterable back in.
                Tables\Filters\TernaryFilter::make('archived_at')
                    ->label(__('Archived'))
                    ->nullable()
                    ->trueLabel(__('Archived groups only'))
                    ->falseLabel(__('Active groups only'))
                    ->placeholder(__('All groups'))
                    ->default(false),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('archive')
                    ->label(__('Archive'))
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Archiving ends this group\'s access grants for members who have no other source of access. Membership and grant history are kept.'))
                    ->visible(fn (Group $record): bool => ! $record->isArchived())
                    ->action(function (Group $record): void {
                        app(GroupAccessService::class)->archiveGroup($record, Filament::auth()->user());
                    }),
            ]);
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
