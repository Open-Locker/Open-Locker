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
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GroupAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'groupAccesses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
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
                    ->placeholder(fn (GroupCompartmentAccess $record): string => ($record->group?->isArchived() ?? false)
                        ? __('Inactive (group archived)')
                        : __('Active'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('grantAccess')
                    ->label(__('Grant group access'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->form($this->grantFormWithMultipleGroupWarning())
                    ->action(function (array $data): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(GroupAccessService::class);

                        $groups = Group::query()
                            ->unarchived()
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
                Action::make('revokeAccess')
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

                        /** @var Group $group */
                        $group = $record->group;

                        app(GroupAccessService::class)->revokeCompartmentAccess(
                            group: $group,
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
            Group::query()
                ->unarchived()
                ->whereDoesntHave(
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

    private function grantFormWithMultipleGroupWarning(): array
    {
        $form = AccessPickerOptions::grantForm(
            'group_ids',
            __('Groups'),
            fn (): array => $this->grantableGroupOptions(),
        );

        // Make the picker reactive so the warning callout can appear immediately.
        $form[0] = $form[0]->live();

        array_unshift(
            $form,
            Callout::make(__('Compartment already has group access'))
                ->warning()
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $this->shouldWarnAboutMultipleGroups($get('group_ids')))
                ->description(fn (Get $get): string => $this->multipleGroupsWarningDescription($get('group_ids'))),
        );

        return $form;
    }

    /**
     * @param  array<int, int|string>|null  $selectedGroupIds
     */
    private function shouldWarnAboutMultipleGroups(?array $selectedGroupIds): bool
    {
        /** @var Compartment $compartment */
        $compartment = $this->getOwnerRecord();

        $groupIds = array_values(array_filter(
            $selectedGroupIds ?? [],
            static fn (mixed $value): bool => filled($value),
        ));

        if ($groupIds === []) {
            return false;
        }

        $existingGroupCount = $compartment->activeGroupAccesses()->count();

        return ($existingGroupCount + count($groupIds)) > 1;
    }

    /**
     * @param  array<int, int|string>|null  $selectedGroupIds
     */
    private function multipleGroupsWarningDescription(?array $selectedGroupIds): string
    {
        /** @var Compartment $compartment */
        $compartment = $this->getOwnerRecord();
        $compartment->loadMissing('lockerBank');

        $existingGroups = $compartment->activeGroupAccesses()
            ->with('group:id,name')
            ->get()
            ->pluck('group.name')
            ->filter()
            ->values()
            ->all();

        $groupIds = array_values(array_filter(
            $selectedGroupIds ?? [],
            static fn (mixed $value): bool => filled($value),
        ));

        $lines = [
            __('This would result in multiple groups having active access to the same compartment.'),
            __('Compartment: :compartment', ['compartment' => $this->compartmentLabel($compartment)]),
        ];

        if ($existingGroups !== []) {
            $lines[] = __('Groups with access already: :groups', ['groups' => implode(', ', $existingGroups)]);
        } else {
            $lines[] = __('Groups with access already: none');
        }

        if (count($groupIds) > 1) {
            $selectedGroupNames = Group::query()
                ->unarchived()
                ->whereIn('id', $groupIds)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $lines[] = __('You are granting access to multiple groups at once: :groups', [
                'groups' => implode(', ', $selectedGroupNames),
            ]);
        }

        return implode("\n", $lines);
    }

    private function compartmentLabel(Compartment $compartment): string
    {
        $bankName = $compartment->lockerBank?->name ?? __('Unknown');

        return sprintf('%s / #%d', $bankName, (int) $compartment->number);
    }

    private function currentUserCanManageAccess(): bool
    {
        // Group-level compartment access is admin-only, gated by `groups.manage` (#48).
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }
}
