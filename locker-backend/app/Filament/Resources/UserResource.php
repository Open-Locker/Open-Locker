<?php

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Models\User;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return self::actor()?->can(Permission::UsersManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::actor()?->can(Permission::UsersManage->value) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof User && (self::actor()?->can(Permission::UsersManage->value) ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof User && self::canManageRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof User && self::canManageRecord($record);
    }

    public static function canDeleteAny(): bool
    {
        return self::actor()?->can(Permission::UsersManage->value) ?? false;
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('first_name')
                    ->required()
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
                Forms\Components\TextInput::make('last_name')
                    ->required()
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('first_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('last_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->isAdmin()),
                Tables\Columns\IconColumn::make('terms_current_accepted')
                    ->label('Current terms accepted')
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasAcceptedCurrentTerms()),
                Tables\Columns\TextColumn::make('latest_terms_version')
                    ->label('Last accepted terms version')
                    ->state(fn (User $record): ?int => $record->latestAcceptedTermsVersion())
                    ->placeholder('-'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->authorize(fn (User $record): bool => self::canView($record))
                    ->label(fn (User $record): string => self::canEdit($record) ? 'Edit' : 'View'),
            ])->actionsAlignment('left')
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->before(function (\Filament\Actions\DeleteBulkAction $action, Collection $records) {
                            if ($records->contains(fn (User $record): bool => ! self::canManageRecord($record))) {
                                Notification::make()
                                    ->title('Aktion abgebrochen')
                                    ->body('Dieser Nutzer kann nicht gelöscht werden.')
                                    ->danger()
                                    ->send();
                                $action->cancel();

                                return;
                            }

                            $adminCount = User::adminRoleCount();
                            $deletedAdmins = $records->filter(fn (User $record): bool => $record->isAdmin())->count();

                            if ($adminCount - $deletedAdmins < 1) {
                                Notification::make()
                                    ->title('Aktion abgebrochen')
                                    ->body('Der letzte Admin kann nicht gelöscht werden.')
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

    public static function canManageRecord(User $record): bool
    {
        $actor = self::actor();

        if (! $actor?->can(Permission::UsersManage->value)) {
            return false;
        }

        if ($actor->can(Permission::RolesManage->value)) {
            return true;
        }

        return ! $record->isAdmin();
    }

    private static function actor(): ?User
    {
        $actor = Auth::user();

        return $actor instanceof User ? $actor : null;
    }
}
