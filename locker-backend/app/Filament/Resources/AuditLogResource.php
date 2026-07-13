<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditEventPresenter;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin audit log (issue #109, ADR-0026): a read-only view over the event store
 * scoped to admin-meaningful events, rendered as human-readable entries.
 *
 * Backed directly by `stored_events` via {@see AuditEvent}; there is no separate
 * read model. Performance is kept bounded by the indexed event_class/created_at
 * filters and a default sort on the primary key.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        // System-wide audit trail is admin-only (mirrors Terms / Roles screens).
        return auth()->user()?->can(Permission::SystemConfigure->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Setup');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit Log');
    }

    public static function getModelLabel(): string
    {
        return __('audit entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit entries');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        $presenter = app(AuditEventPresenter::class);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->state(fn (AuditEvent $record): ?string => $presenter->categoryLabel($record->event_class)),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Event'))
                    ->badge()
                    ->color('gray')
                    ->state(fn (AuditEvent $record): string => $presenter->label($record->event_class)),
                Tables\Columns\TextColumn::make('actor')
                    ->label(__('Actor'))
                    ->state(fn (AuditEvent $record): ?string => $presenter->actorName($record))
                    ->placeholder(__('System')),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('Description'))
                    ->state(fn (AuditEvent $record): string => $presenter->describe($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('aggregate_uuid')
                    ->label(__('Aggregate'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event_class')
                    ->label(__('Event type'))
                    ->options($presenter->eventTypeOptions())
                    ->multiple()
                    ->searchable(),
                Tables\Filters\SelectFilter::make('actor')
                    ->label(__('Actor'))
                    ->options(fn (): array => User::query()
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->id => $user->fullName()])
                        ->all())
                    ->query(function (Builder $query, array $data) use ($presenter): Builder {
                        $actorId = $data['value'] ?? null;

                        if (blank($actorId)) {
                            return $query;
                        }

                        // The performing user is stored under different JSON keys
                        // depending on the event; match any of them. Compare as an
                        // int so it works on both Postgres (->>) and SQLite
                        // (json_extract returns a native integer).
                        return $query->where(function (Builder $inner) use ($presenter, $actorId): void {
                            foreach ($presenter->actorJsonKeys() as $key) {
                                $inner->orWhere("event_properties->{$key}", (int) $actorId);
                            }
                        });
                    }),
                Tables\Filters\Filter::make('aggregate')
                    ->schema([
                        Forms\Components\TextInput::make('aggregate_uuid')
                            ->label(__('Aggregate'))
                            ->placeholder(__('Locker bank / compartment / group ID')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['aggregate_uuid'] ?? null,
                        fn (Builder $q, string $uuid): Builder => $q->where('aggregate_uuid', $uuid),
                    )),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label(__('From')),
                        Forms\Components\DatePicker::make('until')
                            ->label(__('Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Scope to the curated, admin-meaningful events (ADR-0026); the
        // whitelist lives in the presenter as the single source of truth.
        return parent::getEloquentQuery()
            ->whereIn('event_class', app(AuditEventPresenter::class)->auditableEventClasses());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLog::route('/'),
        ];
    }
}
