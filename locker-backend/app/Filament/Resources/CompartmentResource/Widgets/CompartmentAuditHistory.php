<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompartmentResource\Widgets;

use App\Filament\Resources\AuditLogResource;
use App\Models\AuditEvent;
use App\Support\Audit\AuditEventPresenter;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit history of one compartment on its detail page (#370), so managers can
 * see who opened it last without filtering the global audit log. It shows
 * the same event classes the audit log shows the signed-in user.
 */
class CompartmentAuditHistory extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AuditLogResource::canAccess();
    }

    public function table(Table $table): Table
    {
        $presenter = app(AuditEventPresenter::class);

        return $table
            ->heading(__('History'))
            ->query(fn () => AuditEvent::query()
                ->whereIn('event_class', AuditLogResource::visibleEventClasses())
                ->where('event_properties->compartmentUuid', (string) $this->record?->getKey()))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime(),
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
            ]);
    }
}
