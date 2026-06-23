<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\LockerService;
use App\StorableEvents\CompartmentContentNoteUpdated;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

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

    /**
     * Build the content-note change history for a compartment from the event store.
     *
     * @return list<array{changed_at: string, actor: string, note: ?string}>
     */
    private function noteHistoryFor(Compartment $record): array
    {
        $events = EloquentStoredEvent::query()
            ->where('event_class', CompartmentContentNoteUpdated::class)
            ->where('aggregate_uuid', $record->id)
            ->orderByDesc('id')
            ->get();

        $actorIds = $events->pluck('event_properties.actorUserId')->filter()->unique();
        $actorNames = User::query()->whereIn('id', $actorIds)->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName()]);

        return $events->map(function (EloquentStoredEvent $event) use ($actorNames): array {
            $properties = $event->event_properties;
            $actorId = $properties['actorUserId'] ?? null;

            return [
                'changed_at' => Carbon::parse($event->created_at)->toDayDateTimeString(),
                'actor' => $actorNames[$actorId] ?? "User #{$actorId}",
                'note' => $properties['note'] ?? null,
            ];
        })->all();
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
                Tables\Columns\TextColumn::make('content_note')
                    ->label('Note')
                    ->placeholder('No note')
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Compartment $record): ?string => $record->content_note)
                    ->description(fn (Compartment $record): ?string => $record->content_note_updated_at
                        ? 'Updated '.$record->content_note_updated_at->diffForHumans()
                        : null)
                    ->action(
                        Action::make('noteHistory')
                            ->label('Note history')
                            ->icon('heroicon-m-clock')
                            ->modalHeading(fn (Compartment $record): string => "Note history — compartment #{$record->number}")
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Close')
                            ->modalWidth(Width::Medium)
                            ->infolist([
                                RepeatableEntry::make('noteHistory')
                                    ->hiddenLabel()
                                    ->state(fn (Compartment $record): array => $this->noteHistoryFor($record))
                                    ->schema([
                                        TextEntry::make('note')
                                            ->hiddenLabel()
                                            ->placeholder('Note cleared')
                                            ->weight(FontWeight::Medium)
                                            ->columnSpanFull(),
                                        TextEntry::make('actor')
                                            ->hiddenLabel()
                                            ->icon('heroicon-m-user')
                                            ->size('sm')
                                            ->color('gray')
                                            ->columnSpanFull(),
                                        TextEntry::make('changed_at')
                                            ->hiddenLabel()
                                            ->icon('heroicon-m-clock')
                                            ->size('sm')
                                            ->color('gray')
                                            ->columnSpanFull(),
                                    ])
                                    ->gap(false)
                                    ->extraAttributes(['style' => 'max-height: 60vh; overflow-y: auto;']),
                            ]),
                    ),
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
