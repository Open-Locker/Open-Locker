<?php

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\CompartmentAccessesRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\GroupMembershipsRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\OrganizationsRelationManager;
use App\Models\User;
use App\Services\UserAdministrationService;
use App\Support\Organizations\OrganizationContext;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    /**
     * A user is a global identity with memberships, not a row an organization owns.
     * Which users are visible is decided by membership, not by Filament's scope.
     */
    protected static bool $isScopedToTenant = false;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Access management');
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
                    ->label(__('First name'))
                    ->required()
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
                Forms\Components\TextInput::make('last_name')
                    ->label(__('Last name'))
                    ->required()
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
                Forms\Components\TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    // Checked against every user, not just this organization's:
                    // email is globally unique, so without this the collision
                    // surfaced as a database error. The person may well exist in
                    // another operator, where this admin cannot see them — hence
                    // a message that says what to do rather than just "taken".
                    ->unique(table: User::class, column: 'email', ignoreRecord: true)
                    ->validationMessages([
                        'unique' => __('An account with this email already exists. If they belong to another organization, a platform administrator can add them to this one.'),
                    ])
                    ->disabled(fn (?User $record): bool => $record instanceof User && ! self::canEdit($record)),
                TextEntry::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->state(fn (?User $record): array => $record instanceof User ? self::roleLabels($record) : [])
                    ->visible(fn (?User $record): bool => $record instanceof User),
            ]);
    }

    /**
     * Localized labels for the user's assigned roles; users without any
     * stored role binding are plain users.
     *
     * @return list<string>
     */
    public static function roleLabels(User $user): array
    {
        $labels = [];

        foreach ($user->roleNames() as $roleName) {
            $role = Role::tryFrom($roleName);

            if ($role !== null) {
                $labels[] = $role->label();
            }
        }

        return $labels === [] ? [Role::User->label()] : $labels;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('userRoles'))
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
                Tables\Columns\TextColumn::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->state(fn (User $record): array => self::roleLabels($record)),
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
                \Filament\Actions\EditAction::make()
                    ->authorize(fn (User $record): bool => self::canView($record))
                    ->label(fn (User $record): string => self::canEdit($record) ? __('Edit') : __('View')),
            ])->actionsAlignment('left')
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        // Both guards below need the actual records. Without this
                        // the "select all" path hands Filament a bare query and
                        // deletes through it, skipping model events entirely.
                        ->fetchSelectedRecords()
                        ->before(function (\Filament\Actions\DeleteBulkAction $action, Collection $records) {
                            if ($records->contains(fn (Model $record): bool => $record instanceof User && ! self::canManageRecord($record))) {
                                Notification::make()
                                    ->title(__('Cannot delete user'))
                                    ->body(__('This user cannot be deleted.'))
                                    ->danger()
                                    ->send();
                                $action->cancel();

                                return;
                            }

                            $adminCount = User::adminRoleCount(app(OrganizationContext::class)->currentId());
                            $deletedAdmins = $records->filter(fn (Model $record): bool => $record instanceof User && $record->isAdmin())->count();

                            if ($adminCount - $deletedAdmins < 1) {
                                Notification::make()
                                    ->title(__('Cannot delete user'))
                                    ->body(__('The last admin cannot be deleted.'))
                                    ->danger()
                                    ->send();
                                $action->cancel();
                            }
                        })
                        // Deleting the selection one record at a time can strand
                        // the installation without an admin when another request
                        // demotes one in between, so the service commits the whole
                        // selection under a lock or none of it.
                        ->using(function (\Filament\Actions\DeleteBulkAction $action, Collection $records): void {
                            $actor = self::actor();
                            abort_unless($actor instanceof User, 403);

                            $deleted = app(UserAdministrationService::class)->deleteUsers(
                                actor: $actor,
                                targets: $records->filter(fn (Model $record): bool => $record instanceof User),
                            );

                            if ($deleted) {
                                return;
                            }

                            $action->reportCompleteBulkProcessingFailure();

                            Notification::make()
                                ->title(__('Cannot delete user'))
                                ->body(__('The last admin cannot be deleted.'))
                                ->danger()
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * A user is a global identity, so this resource cannot be tenant-scoped the
     * way an owned table is — but an administrator of one operator must not be
     * shown another's people. Membership in the organization being acted in is
     * what decides, including for a platform admin, who sees the organization
     * they have entered like everyone else.
     *
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'organizations',
            fn (Builder $organizations) => $organizations->whereKey(
                app(OrganizationContext::class)->currentId(),
            ),
        );
    }

    public static function getRelations(): array
    {
        return [
            CompartmentAccessesRelationManager::class,
            GroupMembershipsRelationManager::class,
            OrganizationsRelationManager::class,
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

        if (! $actor instanceof User) {
            return false;
        }

        return app(UserAdministrationService::class)->canManageUser($actor, $record);
    }

    private static function actor(): ?User
    {
        $actor = Auth::user();

        return $actor instanceof User ? $actor : null;
    }
}
