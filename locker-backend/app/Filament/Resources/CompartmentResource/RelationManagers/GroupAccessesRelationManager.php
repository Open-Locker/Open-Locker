<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentResource\RelationManagers;

use App\Enums\Permission;
use App\Filament\Support\AccessPickerOptions;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GroupAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'groupAccesses';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Groups');
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('group.name')
                    ->label(__('Group'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('granted_at')
                    ->label(__('Granted at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('Expires at'))
                    ->dateTime()
                    ->placeholder(__('Never'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')
                    ->label(__('Revoked at'))
                    ->dateTime()
                    ->placeholder(__('Active'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('grantAccess')
                    ->label(__('Grant group access'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->form(AccessPickerOptions::grantForm(
                        'group_ids',
                        __('Groups'),
                        fn (): array => $this->grantableGroupOptions(),
                    ))
                    ->action(function (array $data): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(GroupAccessService::class);

                        $groups = Group::query()
                            ->whereIn('id', $data['group_ids'])
                            ->get();

                        foreach ($groups as $group) {
                            $service->grantCompartmentAccess(
                                group: $group,
                                compartment: $compartment,
                                expiresAt: $expiresAt,
                                notes: $data['notes'] ?? null,
                                actor: $actor,
                            );
                        }

                        $this->resetTable();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('revokeAccess')
                    ->label(__('Revoke'))
                    ->color('danger')
                    ->icon('heroicon-m-no-symbol')
                    ->visible(fn (GroupCompartmentAccess $record): bool => $this->currentUserCanManageAccess() && $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(function (GroupCompartmentAccess $record): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        app(GroupAccessService::class)->revokeCompartmentAccess(
                            group: $record->group,
                            compartment: $compartment,
                            actor: $actor,
                        );

                        $this->resetTable();
                    }),
            ]);
    }

    /**
     * Groups that can be granted access to the owner compartment: excludes
     * groups that already have active access to it.
     *
     * @return array<string, string>
     */
    private function grantableGroupOptions(): array
    {
        /** @var Compartment $compartment */
        $compartment = $this->getOwnerRecord();

        return AccessPickerOptions::groups(
            Group::query()->whereDoesntHave(
                'compartmentAccesses',
                fn (Builder $query): Builder => $query
                    ->where('compartment_id', $compartment->id)
                    ->whereNull('revoked_at')
                    ->where(function (Builder $builder): void {
                        $builder->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
            )
        );
    }

    private function currentUserCanManageAccess(): bool
    {
        // Group-level compartment access is admin-only, gated by `groups.manage` (#48).
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }
}
