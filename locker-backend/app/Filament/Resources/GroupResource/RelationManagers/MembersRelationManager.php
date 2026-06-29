<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\Permission;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Members');
    }

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
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label(__('User'))
                            ->required()
                            ->searchable()
                            ->options(
                                User::query()
                                    ->orderBy('first_name')
                                    ->get()
                                    ->mapWithKeys(fn (User $user): array => [
                                        $user->id => sprintf('%s (%s)', $user->fullName(), $user->email),
                                    ])
                                    ->all()
                            ),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label(__('Expires at'))
                            ->seconds(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();
                        /** @var User $user */
                        $user = User::query()->findOrFail($data['user_id']);

                        $expiresAt = filled($data['expires_at'])
                            ? Carbon::parse($data['expires_at'])
                            : null;

                        app(GroupAccessService::class)->addUser(
                            group: $group,
                            user: $user,
                            expiresAt: $expiresAt,
                            actor: $actor,
                        );

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

    private function currentUserCanManageGroups(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }
}
