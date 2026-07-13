<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\Permission;
use App\Filament\Resources\UserResource;
use App\Filament\Support\AccessPickerOptions;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompartmentAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'compartmentAccesses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Compartment accesses');
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
                Tables\Columns\TextColumn::make('granted_by_display_name')
                    ->label(__('Granted by'))
                    ->state(fn (CompartmentAccess $record): ?string => $record->grantedByUser?->fullName())
                    ->placeholder(__('System'))
                    ->toggleable(),
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
                Tables\Columns\TextColumn::make('revoked_by_display_name')
                    ->label(__('Revoked by'))
                    ->state(fn (CompartmentAccess $record): ?string => $record->revokedByUser?->fullName())
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('compartment.latestOpenRequest.status')
                    ->label(__('Last open status'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'opened' => 'success',
                        'failed', 'denied' => 'danger',
                        'sent', 'accepted', 'requested' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(__('No requests'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('compartment.latestOpenRequest.opened_at')
                    ->label(__('Last opened at'))
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('grantAccess')
                    ->label(__('Grant access'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->form(AccessPickerOptions::grantForm(
                        'compartment_ids',
                        __('Compartments'),
                        fn (): array => $this->grantableCompartmentOptions(),
                    ))
                    ->action(function (array $data): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(CompartmentAccessService::class);

                        $compartments = Compartment::query()
                            ->whereIn('id', $data['compartment_ids'])
                            ->get();

                        foreach ($compartments as $compartment) {
                            $service->grantAccess(
                                user: $user,
                                compartment: $compartment,
                                expiresAt: $expiresAt,
                                notes: $data['notes'] ?? null,
                                actor: $actor
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
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->requiresConfirmation()
                    ->action(function (CompartmentAccess $record): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        app(CompartmentAccessService::class)->revokeAccess(
                            user: $user,
                            compartment: $record->compartment,
                            actor: $actor
                        );

                        $this->resetTable();
                    }),
            ]);
    }

    /**
     * Grantable compartments for the owner user: excludes compartments the
     * user already has active access to.
     *
     * @return array<string, string>
     */
    private function grantableCompartmentOptions(): array
    {
        /** @var User $user */
        $user = $this->getOwnerRecord();

        return AccessPickerOptions::compartments(
            Compartment::query()->whereDoesntHave(
                'activeAccesses',
                fn (Builder $query): Builder => $query->where('user_id', $user->id)
            )
        );
    }

    private function currentUserCanManageAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->can(Permission::CompartmentAccessManage->value)) {
            return false;
        }

        /** @var User $owner */
        $owner = $this->getOwnerRecord();

        return UserResource::canManageRecord($owner);
    }
}
