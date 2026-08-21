<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CompartmentDoorState;
use App\Enums\Permission;
use App\Filament\Resources\CompartmentResource\Pages;
use App\Filament\Resources\CompartmentResource\RelationManagers\GroupAccessesRelationManager;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
use App\Filament\Support\CompartmentDoorStateColumn;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CompartmentResource extends Resource
{
    protected static ?string $model = Compartment::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Compartments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Compartment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Compartments');
    }

    public static function canAccess(): bool
    {
        // Operational compartment access management for admins and managers (#48, #95).
        return auth()->user()?->can(Permission::CompartmentAccessManage->value) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        // Provisioning new compartments is technical setup; it stays on the Locker Bank screen.
        return false;
    }

    public static function form(Schema $form): Schema
    {
        return $form->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('lockerBank.name')
                ->label(__('Locker bank')),
            TextEntry::make('number')
                ->label(__('Compartment'))
                ->prefix('#'),
            TextEntry::make('door_state')
                ->label(__('Door'))
                ->badge()
                ->formatStateUsing(fn (CompartmentDoorState $state): string => $state->label())
                ->color(fn (CompartmentDoorState $state): string => match ($state) {
                    CompartmentDoorState::Open => 'warning',
                    CompartmentDoorState::Closed => 'success',
                    CompartmentDoorState::Unknown => 'gray',
                }),
            TextEntry::make('content_note')
                ->label(__('Note'))
                ->placeholder(__('No note')),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lockerBank', 'latestOpenRequest']);
    }

    /**
     * A jammed or already-open compartment reports `door_state: closed` exactly
     * like a healthy one, so the last open attempt is the only signal that the
     * lock needs attention (ADR-0031).
     */
    public static function table(Table $table): Table
    {
        return $table
            // Bank ordering comes from the group itself; within a bank, compartments
            // read naturally by number.
            ->defaultSort('number')
            // Compartments are only meaningful per locker bank, so the list is always
            // grouped and folded shut — the admin picks one bank instead of scanning
            // every compartment in the system (#167).
            ->groups([
                Group::make('lockerBank.name')
                    ->label(__('Locker bank'))
                    // The header is unambiguous on its own; the label prefix just
                    // repeats "Locker bank:" on every row group.
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(),
            ])
            ->defaultGroup('lockerBank.name')
            ->collapsedGroupsByDefault()
            ->groupingSettingsHidden()
            // Record pagination would split a locker bank across pages. Every group
            // loads collapsed, so the page is a short list of bank headers no matter
            // how many compartments exist — the grouping is the pagination (#167).
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('latestOpenRequest'))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label(__('Compartment'))
                    ->prefix('#')
                    ->sortable(),
                CompartmentDoorStateColumn::column(),
                Tables\Columns\TextColumn::make('active_accesses_count')
                    ->label(__('Direct users'))
                    ->counts('activeAccesses')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('content_note')
                    ->label(__('Note'))
                    ->placeholder(__('No note'))
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Compartment $record): ?string => $record->content_note)
                    ->toggleable(),
            ])
            // No search or filters: the collapsed bank groups are the only navigation
            // this list needs, and a locker-bank filter would just duplicate them (#167).
            ->actions([
                Action::make('access')
                    ->label(__('Access'))
                    ->icon('heroicon-m-key')
                    ->url(fn (Compartment $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-bolt')
                    ->requiresConfirmation()
                    ->visible(fn (Compartment $record): bool => (Filament::auth()->user()?->can(Permission::CompartmentOpen->value) ?? false)
                        && CompartmentDoorStateColumn::canBeOpened($record))
                    ->action(function (Compartment $record): void {
                        try {
                            $user = Filament::auth()->user();
                            if (! $user instanceof User) {
                                Notification::make()
                                    ->title(__('Unable to open compartment'))
                                    ->body(__('Your session has expired. Please log in again.'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            app(CompartmentAccessService::class)->requestOpen($user, $record);
                        } catch (\Throwable $e) {
                            Log::error('Failed to request compartment opening from Filament.', [
                                'compartment_id' => $record->id,
                                'locker_bank_id' => $record->locker_bank_id,
                                'number' => $record->number,
                                'error' => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title(__('Failed to send open command'))
                                ->body(__('Please try again. Details are in the server log.'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            UserAccessesRelationManager::class,
            GroupAccessesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompartments::route('/'),
            'view' => Pages\ViewCompartment::route('/{record}'),
        ];
    }
}
