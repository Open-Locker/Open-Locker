<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentResource\RelationManagers;

use App\Enums\Permission;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class UserAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'accesses';

    protected static ?string $title = 'Users';

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
                    ->label('User')
                    ->description(fn (CompartmentAccess $record): ?string => $record->user?->fullName())
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('granted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('granted_by_display_name')
                    ->label('Granted by')
                    ->state(fn (CompartmentAccess $record): ?string => $record->grantedByUser?->fullName())
                    ->placeholder('System')
                    ->toggleable(),
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
                    ->label('Grant user access')
                    ->icon('heroicon-m-key')
                    ->visible(fn (): bool => $this->currentUserCanManageAccess())
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('User')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where('email', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (User $user): array => [
                                    $user->id => sprintf('%s (%s)', $user->fullName(), $user->email),
                                ])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::find($value)?->email),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Expires at')
                            ->seconds(false),
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data): void {
                        /** @var Compartment $compartment */
                        $compartment = $this->getOwnerRecord();
                        /** @var User|null $actor */
                        $actor = Filament::auth()->user();
                        /** @var User $user */
                        $user = User::query()->findOrFail($data['user_id']);

                        $expiresAt = filled($data['expires_at'])
                            ? Carbon::parse($data['expires_at'])
                            : null;

                        app(CompartmentAccessService::class)->grantAccess(
                            user: $user,
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
                    ->label('Revoke')
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

    private function currentUserCanManageAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::CompartmentAccessManage->value);
    }
}
