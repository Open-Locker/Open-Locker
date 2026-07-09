<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\Permission;
use App\Filament\Support\AccessPickerOptions;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->state(fn (User $record): string => $record->fullName())
                    ->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('pivot.expires_at')
                    ->label(__('Expires at'))
                    ->dateTime()
                    ->placeholder(__('Never')),
                Tables\Columns\TextColumn::make('pivot.revoked_at')
                    ->label(__('Removed at'))
                    ->dateTime()
                    ->placeholder(__('Active')),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('addMember')
                    ->label(__('Add member'))
                    ->icon('heroicon-m-user-plus')
                    ->visible(fn (): bool => $this->currentUserCanManageGroups())
                    ->form(AccessPickerOptions::grantForm(
                        'user_ids',
                        __('Users'),
                        fn (): array => $this->addableUserOptions(),
                        withNotes: false,
                    ))
                    ->action(function (array $data): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(GroupAccessService::class);

                        $users = User::query()
                            ->whereIn('id', $data['user_ids'])
                            ->get();

                        foreach ($users as $user) {
                            $service->addUser(
                                group: $group,
                                user: $user,
                                expiresAt: $expiresAt,
                                actor: $actor,
                            );
                        }

                        $this->resetTable();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('removeMember')
                    ->label(__('Remove'))
                    ->color('danger')
                    ->icon('heroicon-m-user-minus')
                    ->visible(fn (): bool => $this->currentUserCanManageGroups())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        app(GroupAccessService::class)->removeUser(
                            group: $group,
                            user: $record,
                            actor: $actor,
                        );

                        $this->resetTable();
                    }),
            ]);
    }

    /**
     * Users who can be added to the owner group: excludes users that are
     * already active members.
     *
     * @return array<string, string>
     */
    private function addableUserOptions(): array
    {
        /** @var Group $group */
        $group = $this->getOwnerRecord();

        $activeMemberIds = $group->members()
            ->wherePivotNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('group_user.expires_at')
                    ->orWhere('group_user.expires_at', '>', now());
            })
            ->pluck('users.id')
            ->all();

        return AccessPickerOptions::users(
            User::query()->whereNotIn('id', $activeMemberIds)
        );
    }

    private function currentUserCanManageGroups(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }
}
