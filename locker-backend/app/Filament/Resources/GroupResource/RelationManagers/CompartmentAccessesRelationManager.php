<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\RelationManagers;

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

class CompartmentAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'compartmentAccesses';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Compartment access');
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
                Tables\Columns\TextColumn::make('compartment.number')
                    ->label(__('Compartment'))
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('compartment.lockerBank.name')
                    ->label(__('Locker bank'))
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
                    ->label(__('Grant access'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageGroups() && ! $this->ownerGroupIsArchived())
                    ->form(AccessPickerOptions::grantForm(
                        'compartment_ids',
                        __('Compartments'),
                        fn (): array => $this->grantableCompartmentOptions(),
                    ))
                    ->action(function (array $data): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(GroupAccessService::class);

                        $compartments = Compartment::query()
                            ->whereIn('id', $data['compartment_ids'])
                            ->get();

                        foreach ($compartments as $compartment) {
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
                    ->label(__('Revoke access'))
                    ->color('danger')
                    ->icon('heroicon-m-no-symbol')
                    ->visible(fn (): bool => $this->currentUserCanManageGroups())
                    ->requiresConfirmation()
                    ->action(function (GroupCompartmentAccess $record): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        app(GroupAccessService::class)->revokeCompartmentAccess(
                            group: $group,
                            compartment: $record->compartment,
                            actor: $actor,
                        );

                        $this->resetTable();
                    }),
            ]);
    }

    /**
     * Grantable compartments for the owner group: excludes compartments the
     * group already has active access to.
     *
     * @return array<string, string>
     */
    private function grantableCompartmentOptions(): array
    {
        /** @var Group $group */
        $group = $this->getOwnerRecord();

        return AccessPickerOptions::compartments(
            Compartment::query()->whereDoesntHave(
                'groupAccesses',
                fn (Builder $query): Builder => $query
                    ->where('group_id', $group->id)
                    ->whereNull('revoked_at')
                    ->where(function (Builder $builder): void {
                        $builder->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
            )
        );
    }

    private function currentUserCanManageGroups(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }

    private function ownerGroupIsArchived(): bool
    {
        /** @var Group $group */
        $group = $this->getOwnerRecord();

        return $group->isArchived();
    }
}
