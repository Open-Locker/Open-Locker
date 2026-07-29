<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\GroupResource;
use App\Models\Group;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GroupMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'activeGroups';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Group memberships');
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modelLabel(__('Group'))
            ->pluralModelLabel(__('Groups'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->url(fn (Group $record): string => GroupResource::getUrl('edit', ['record' => $record]))
                    ->color('primary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(60)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pivot.expires_at')
                    ->label(__('Expires at'))
                    ->dateTime()
                    ->placeholder(__('Never')),
            ]);
    }
}
