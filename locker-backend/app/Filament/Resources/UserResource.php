<?php

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\GroupMembershipsRelationManager;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getModelLabel(): string
    {
        return __('User');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Users');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::UsersManage->value) ?? false;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->label(__('First name'))
                    ->required(),
                Forms\Components\TextInput::make('last_name')
                    ->label(__('Last name'))
                    ->required(),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->label(__('First name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->label(__('Last name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label(__('Email verified at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('is_admin_since')
                    ->label(__('Admin since'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('terms_current_accepted')
                    ->label(__('Current terms accepted'))
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasAcceptedCurrentTerms()),
                Tables\Columns\TextColumn::make('latest_terms_version')
                    ->label(__('Last accepted terms version'))
                    ->state(fn (User $record): ?int => $record->latestAcceptedTermsVersion())
                    ->placeholder('-'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])->actionsAlignment('left')
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->before(function (\Filament\Actions\DeleteBulkAction $action, Collection $records) {
                            $adminCount = User::whereNotNull('is_admin_since')->count();
                            $deletedAdmins = $records->whereNotNull('is_admin_since')->count();

                            if ($adminCount - $deletedAdmins < 1) {
                                Notification::make()
                                    ->title(__('Action cancelled'))
                                    ->body(__('The last admin cannot be deleted.'))
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            CompartmentAccessesRelationManager::class,
            GroupMembershipsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
