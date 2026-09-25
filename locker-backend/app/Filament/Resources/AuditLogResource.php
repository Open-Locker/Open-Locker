<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditEvent;
use App\Models\User;
use App\StorableEvents\PlatformAdminEnteredOrganization;
use App\StorableEvents\UserRoleGranted;
use App\StorableEvents\UserRoleRevoked;
use App\Support\Audit\AuditEventPresenter;
use App\Support\EventSourcing\OrganizationStamp;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin audit log (#109): a read-only view over the event store
 * scoped to admin-meaningful events, rendered as human-readable entries.
 *
 * Backed directly by `stored_events` via {@see AuditEvent}; there is no separate
 * read model. Performance is kept bounded by the indexed event_class/created_at
 * filters and a default sort on the primary key.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    /**
     * A read-only view over the event store, which is shared: rows are filtered by
     * the organization they refer to rather than owned by one.
     */
    protected static bool $isScopedToTenant = false;

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
                    // Rows are confined to this organization; the filter has
                    // to be too. A user is a global identity that nothing
                    // scopes, so an unfiltered dropdown names another
                    // operator's staff.
                    ->options(fn (): array => User::query()
                        ->inCurrentOrganization()
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
        // Scope to the curated, admin-meaningful events; the
        // whitelist lives in the presenter as the single source of truth.
        $query = parent::getEloquentQuery()
            ->whereIn('event_class', app(AuditEventPresenter::class)->auditableEventClasses());

        return self::hidePlatformAdministration(self::confineToCurrentOrganization($query));
    }

    /**
     * Platform administrators belong to no organization, so what concerns them
     * is shown only to them: granting or revoking the role, and their entering
     * an organization (ADR-0065, decision 13).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private static function hidePlatformAdministration(Builder $query): Builder
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isPlatformAdmin()) {
            return $query;
        }

        return $query
            ->where('event_class', '!=', PlatformAdminEnteredOrganization::class)
            ->whereNot(fn (Builder $roleChange): Builder => $roleChange
                ->whereIn('event_class', [UserRoleGranted::class, UserRoleRevoked::class])
                ->where('event_properties->role', Role::PlatformAdmin->value));
    }

    /**
     * The event store is shared, so this resource cannot be tenant-scoped the
     * way an owned table is — the rows are filtered by the organization each
     * event records, which is stamped into its metadata when it happens.
     *
     * Events predating organizations carry no stamp and belong to the default
     * organization, so they are included there and nowhere else.
     *
     * A platform admin sees the organization they have entered, like everyone
     * else: entering is what makes the data visible, and entering is recorded.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private static function confineToCurrentOrganization(Builder $query): Builder
    {
        $organizationId = app(OrganizationContext::class)->currentId();

        if ($organizationId === null) {
            // Fail closed, as everywhere else: no organization in context shows
            // no history rather than all of it.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped->where('meta_data->'.OrganizationStamp::KEY, $organizationId);

            if ($organizationId === DefaultOrganization::id()) {
                $scoped->orWhereNull('meta_data->'.OrganizationStamp::KEY);
            }
        });
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
