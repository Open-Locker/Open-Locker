<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use App\Models\Compartment;
use App\Models\LockerBank;
use App\Services\CompartmentAccessService;
use App\Services\LockerService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class CompartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'compartments';

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('number')
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(1)
                    ->helperText('1-based compartment number (logical ID used by MQTT commands).'),

                Forms\Components\TextInput::make('slave_id')
                    ->label('Slave ID')
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(1)
                    ->maxValue(255)
                    ->helperText('Modbus slave ID of the IO board (1-255).'),

                Forms\Components\TextInput::make('address')
                    ->label('Address')
                    ->numeric()
                    ->required()
                    ->step(1)
                    ->minValue(0)
                    ->helperText('0-based relay address on the given slave. Used for both coil and input.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->sortable()
                    ->label('Compartment')
                    ->prefix('#'),

                Tables\Columns\TextInputColumn::make('slave_id')
                    ->label('Slave ID')
                    ->rules(['nullable', 'integer', 'min:1', 'max:255'])
                    ->tooltip('Modbus slave ID (1-255).'),

                Tables\Columns\TextInputColumn::make('address')
                    ->label('Address')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->tooltip('0-based relay address. Used for both coil and input.'),
                Tables\Columns\TextColumn::make('latestOpenRequest.status')
                    ->label('Last open status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'opened' => 'success',
                        'failed', 'denied' => 'danger',
                        'sent', 'accepted', 'requested' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('No requests')
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestOpenRequest.command_id')
                    ->label('Last command ID')
                    ->copyable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\Action::make('sendConfigToClient')
                    ->label('Send config to client')
                    ->icon('heroicon-m-paper-airplane')
                    ->requiresConfirmation()
                    ->disabled(function (): bool {
                        /** @var LockerBank $lockerBank */
                        $lockerBank = $this->getOwnerRecord();

                        return $lockerBank->provisioned_at === null;
                    })
                    ->action(function (): void {
                        /** @var LockerBank $lockerBank */
                        $lockerBank = $this->getOwnerRecord();

                        try {
                            app(LockerService::class)->applyConfig($lockerBank);

                            Notification::make()
                                ->title('Config queued for sending')
                                ->body('An apply_config command was queued and will be sent via MQTT.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Failed to queue config')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                \Filament\Actions\CreateAction::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-bolt')
                    ->requiresConfirmation()
                    ->action(function (Compartment $record): void {
                        try {
                            $user = Filament::auth()->user();
                            if (! $user instanceof \App\Models\User) {
                                Notification::make()
                                    ->title('Unable to open compartment')
                                    ->body('No authenticated user context available.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $decision = app(CompartmentAccessService::class)->requestOpen($user, $record);

                            $notification = Notification::make()
                                ->title($decision['authorized'] ? 'Open command accepted' : 'Open command denied')
                                ->body("Compartment {$record->number} command ID: {$decision['command_id']}");

                            if ($decision['authorized']) {
                                $notification->success();
                            } else {
                                $notification->danger();
                            }

                            $notification->send();
                        } catch (\Throwable $e) {
                            Log::error('Failed to request compartment opening from Filament.', [
                                'compartment_id' => $record->id,
                                'locker_bank_id' => $record->locker_bank_id,
                                'number' => $record->number,
                                'error' => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('Failed to queue open command')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
