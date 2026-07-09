<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentResource\RelationManagers;

use App\Enums\Permission;
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

class UserAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'accesses';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Users');
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
                Tables\Columns\TextColumn::make('user.email')
                    ->label(__('User'))
                    ->description(fn (CompartmentAccess $record): ?string => $record->user?->fullName())
                    ->searchable()
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
                Tables\Columns\TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('grantAccess')
                    ->label(__('Grant user access'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->form(AccessPickerOptions::grantForm(
                        'user_ids',
                        __('Users'),
                        fn (): array => $this->grantableUserOptions(),
                    ))
                    ->action(function (array $data): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        $expiresAt = AccessPickerOptions::parseExpiresAt($data);

                        $service = app(CompartmentAccessService::class);

                        $users = User::query()
                            ->whereIn('id', $data['user_ids'])
                            ->get();

                        foreach ($users as $user) {
                            $service->grantAccess(
                                user: $user,
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
                    ->visible(fn (CompartmentAccess $record): bool => $this->currentUserCanManageAccess() && $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->action(function (CompartmentAccess $record): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();

                        app(CompartmentAccessService::class)->revokeAccess(
                            user: $record->user,
                            compartment: $compartment,
                            actor: $actor,
                        );

                        $this->resetTable();
                    }),
            ]);
    }

    /**
     * Users who can be granted access to the owner compartment: excludes users
     * that already have active access to it.
     *
     * @return array<string, string>
     */
    private function grantableUserOptions(): array
    {
        /** @var Compartment $compartment */
        $compartment = $this->getOwnerRecord();

        return AccessPickerOptions::users(
            User::query()->whereDoesntHave(
                'activeCompartmentAccesses',
                fn (Builder $query): Builder => $query->where('compartment_id', $compartment->id)
            )
        );
    }

    private function currentUserCanManageAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::CompartmentAccessManage->value);
    }
}
