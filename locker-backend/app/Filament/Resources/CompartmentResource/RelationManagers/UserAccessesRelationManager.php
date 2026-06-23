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
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label(__('User'))
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
                            ->label(__('Expires at'))
                            ->seconds(false),
                        Forms\Components\Textarea::make('notes')
                            ->label(__('Notes'))
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

    private function currentUserCanManageAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can(Permission::CompartmentAccessManage->value);
    }
}
