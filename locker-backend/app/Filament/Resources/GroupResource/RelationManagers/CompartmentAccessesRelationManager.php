<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\GroupCompartmentAccess;
use App\Models\User;
use App\Services\GroupAccessService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class CompartmentAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'compartmentAccesses';

    protected static ?string $title = 'Compartment access';

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
                    ->label('Compartment')
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('compartment.lockerBank.name')
                    ->label('Locker bank')
                    ->sortable(),
                Tables\Columns\TextColumn::make('granted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('revoked_at')
                    ->dateTime()
                    ->placeholder('Active')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('grantAccess')
                    ->label('Grant access')
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageGroups())
                    ->form([
                        Forms\Components\Select::make('compartment_id')
                            ->label('Compartment')
                            ->required()
                            ->searchable()
                            ->options(
                                Compartment::query()
                                    ->with('lockerBank')
                                    ->get()
                                    ->mapWithKeys(fn (Compartment $compartment): array => [
                                        (string) $compartment->id => sprintf(
                                            '%s / #%d',
                                            $compartment->lockerBank->name,
                                            (int) $compartment->number
                                        ),
                                    ])
                                    ->all()
                            ),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expires at')
                            ->seconds(false),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data): void {
                        /** @var Group $group */
                        $group = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();
                        /** @var Compartment $compartment */
                        $compartment = Compartment::query()->findOrFail($data['compartment_id']);

                        $expiresAt = filled($data['expires_at'])
                            ? Carbon::parse($data['expires_at'])
                            : null;

                        app(GroupAccessService::class)->grantCompartmentAccess(
                            group: $group,
                            compartment: $compartment,
                            expiresAt: $expiresAt,
                            notes: $data['notes'] ?? null,
                            actor: $actor,
                        );

                        $this->resetTable();
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('revokeAccess')
                    ->label('Revoke access')
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

    private function currentUserCanManageGroups(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::GroupsManage->value);
    }
}
