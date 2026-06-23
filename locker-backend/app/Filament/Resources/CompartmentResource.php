<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\CompartmentDoorState;
use App\Enums\Permission;
use App\Filament\Resources\CompartmentResource\Pages;
use App\Filament\Resources\CompartmentResource\RelationManagers\GroupAccessesRelationManager;
use App\Filament\Resources\CompartmentResource\RelationManagers\UserAccessesRelationManager;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CompartmentResource extends Resource
{
    protected static ?string $model = Compartment::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationLabel = 'Compartments';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

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
                ->label('Locker bank'),
            TextEntry::make('number')
                ->label('Compartment')
                ->prefix('#'),
            TextEntry::make('door_state')
                ->label('Door')
                ->badge()
                ->formatStateUsing(fn (CompartmentDoorState $state): string => $state->value)
                ->color(fn (CompartmentDoorState $state): string => match ($state) {
                    CompartmentDoorState::Open => 'warning',
                    CompartmentDoorState::Closed => 'success',
                    CompartmentDoorState::Unknown => 'gray',
                }),
            TextEntry::make('content_note')
                ->label('Note')
                ->placeholder('No note'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lockerBank', 'latestOpenRequest']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('lockerBank.name')
            ->columns([
                Tables\Columns\TextColumn::make('lockerBank.name')
                    ->label('Locker bank')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('number')
                    ->label('Compartment')
                    ->prefix('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('door_state')
                    ->label('Door')
                    ->badge()
                    ->formatStateUsing(fn (CompartmentDoorState $state): string => $state->value)
                    ->color(fn (CompartmentDoorState $state): string => match ($state) {
                        CompartmentDoorState::Open => 'warning',
                        CompartmentDoorState::Closed => 'success',
                        CompartmentDoorState::Unknown => 'gray',
                    })
                    ->placeholder('unknown'),
                Tables\Columns\TextColumn::make('activeAccesses_count')
                    ->label('Direct users')
                    ->counts('activeAccesses')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('content_note')
                    ->label('Note')
                    ->placeholder('No note')
                    ->limit(40)
                    ->wrap()
                    ->tooltip(fn (Compartment $record): ?string => $record->content_note)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('locker_bank_id')
                    ->label('Locker bank')
                    ->relationship('lockerBank', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Action::make('access')
                    ->label('Access')
                    ->icon('heroicon-m-key')
                    ->url(fn (Compartment $record): string => static::getUrl('view', ['record' => $record])),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-m-bolt')
                    ->requiresConfirmation()
                    ->visible(fn (Compartment $record): bool => (Filament::auth()->user()?->can(Permission::CompartmentOpen->value) ?? false)
                        && in_array($record->door_state, [CompartmentDoorState::Closed, CompartmentDoorState::Unknown], true))
                    ->action(function (Compartment $record): void {
                        try {
                            $user = Filament::auth()->user();
                            if (! $user instanceof User) {
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

                            $decision['authorized'] ? $notification->success() : $notification->danger();

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
