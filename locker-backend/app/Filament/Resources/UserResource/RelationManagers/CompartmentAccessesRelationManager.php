<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class CompartmentAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'compartmentAccesses';

    public function form(Form $form): Form
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
                Tables\Actions\Action::make('grantAccess')
                    ->label('Grant access')
                    ->icon('heroicon-m-key')
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
                                            $compartment->lockerBank?->name ?? 'Unknown locker bank',
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
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        /** @var Compartment $compartment */
                        $compartment = Compartment::query()->findOrFail($data['compartment_id']);

                        $expiresAt = filled($data['expires_at'])
                            ? Carbon::parse($data['expires_at'])
                            : null;

                        app(CompartmentAccessService::class)->grantAccess(
                            user: $user,
                            compartment: $compartment,
                            expiresAt: $expiresAt,
                            notes: $data['notes'] ?? null
                        );

                        Notification::make()
                            ->title('Access granted')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revokeAccess')
                    ->label('Revoke access')
                    ->color('danger')
                    ->icon('heroicon-m-no-symbol')
                    ->requiresConfirmation()
                    ->action(function (CompartmentAccess $record): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();

                        app(CompartmentAccessService::class)->revokeAccess(
                            user: $user,
                            compartment: $record->compartment
                        );

                        Notification::make()
                            ->title('Access revoked')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
