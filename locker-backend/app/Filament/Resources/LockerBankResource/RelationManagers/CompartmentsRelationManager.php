<?php

declare(strict_types=1);

namespace App\Filament\Resources\LockerBankResource\RelationManagers;

use App\Enums\Permission;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Support\CompartmentDoorStateColumn;
use App\Filament\Support\OpenCompartmentAction;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentService;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class CompartmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'compartments';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Compartments');
    }

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === EditLockerBank::class;
    }

    public function form(Schema $form): Schema
    {
        return $form->schema([]);
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

        return array_values($events->map(function (EloquentStoredEvent $event) use ($actorNames): array {
            $properties = $event->event_properties;
            $actorId = $properties['actorUserId'] ?? null;
            $note = $properties['note'] ?? null;

            return [
                'changed_at' => Carbon::parse($event->created_at)->toDayDateTimeString(),
                'actor' => $actorNames[$actorId] ?? "User #{$actorId}",
                'note' => is_string($note) ? $note : null,
            ];
        })->all());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            // The door badge reads the last open request to flag a jam, so load it
            // with the rows rather than once per compartment.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestOpenRequest'))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->sortable()
                    ->label(__('Compartment'))
                    ->prefix('#'),

                CompartmentDoorStateColumn::column(),

                Tables\Columns\TextColumn::make('content_note')
                    ->label(__('Note'))
                    ->placeholder(__('No note'))
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Compartment $record): ?string => $record->content_note)
                    ->description(fn (Compartment $record): ?string => $record->content_note_updated_at
                        ? (string) __('Updated :time', ['time' => $record->content_note_updated_at->diffForHumans()])
                        : null)
                    ->action(
                        Action::make('noteHistory')
                            ->label(__('Note history'))
                            ->icon('heroicon-m-clock')
                            ->modalHeading(fn (Compartment $record): string => __('Note history — compartment #:number', ['number' => $record->number]))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('Close'))
                            ->modalWidth(Width::Medium)
                            ->infolist([
                                RepeatableEntry::make('noteHistory')
                                    ->hiddenLabel()
                                    ->state(fn (Compartment $record): array => $this->noteHistoryFor($record))
                                    ->schema([
                                        TextEntry::make('note')
                                            ->hiddenLabel()
                                            ->placeholder(__('Note cleared'))
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
                Tables\Columns\TextColumn::make('latestOpenRequest.command_id')
                    ->label(__('Last command ID'))
                    ->copyable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                OpenCompartmentAction::make(),
                Action::make('editContentNote')
                    ->label(__('Edit note'))
                    ->icon('heroicon-m-pencil-square')
                    ->modalHeading(fn (Compartment $record): string => __('Edit note — compartment #:number', ['number' => $record->number]))
                    ->modalWidth(Width::Medium)
                    ->modalSubmitActionLabel(__('Save note'))
                    // The same permission CompartmentService enforces, so the
                    // button is never offered to someone who would be refused.
                    ->visible(fn (): bool => Filament::auth()->user()?->can(Permission::CompartmentAccessManage->value) ?? false)
                    ->fillForm(fn (Compartment $record): array => ['note' => $record->content_note])
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label(__('Note'))
                            ->rows(3)
                            // Matches the column width and the API rule.
                            ->maxLength(80)
                            ->helperText(__('Leave empty to clear the note.')),
                    ])
                    ->action(function (Compartment $record, array $data): void {
                        $user = Filament::auth()->user();
                        if (! $user instanceof User) {
                            Notification::make()
                                ->title(__('Unable to save note'))
                                ->body(__('Your session has expired. Please log in again.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        // Blank clears the note, same as the mobile endpoint.
                        $note = trim((string) ($data['note'] ?? ''));

                        try {
                            app(CompartmentService::class)->updateContentNote(
                                actor: $user,
                                compartment: $record,
                                note: $note === '' ? null : $note,
                            );
                        } catch (AuthorizationException) {
                            Notification::make()
                                ->title(__('Not allowed to edit this note'))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($note === '' ? __('Note cleared') : __('Note updated'))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
