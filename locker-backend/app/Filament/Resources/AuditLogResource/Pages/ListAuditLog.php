<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Support\Audit\AuditEventPresenter;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * @return Collection<int|string, EloquentStoredEvent>|Paginator<int|string, EloquentStoredEvent>|CursorPaginator<int|string, EloquentStoredEvent>
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        $records = parent::getTableRecords();

        $recordsCollection = $records instanceof Collection
            ? $records
            : collect($records->items());

        $presenter = app(AuditEventPresenter::class);

        $presenter->primeUserCache(
            $recordsCollection
                ->filter(fn (mixed $record): bool => $record instanceof EloquentStoredEvent)
                ->map(fn (EloquentStoredEvent $event): ?int => $presenter->actorId($event))
                ->filter()
                ->all(),
        );

        return $records;
    }

    /**
     * Category tabs (Access / Devices / Admin / Terms) on top of the unified
     * log, limited to the categories the user may see. "All" is the default tab.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $categories = AuditLogResource::visibleCategories();

        // A single category would only repeat the "All" tab.
        if (count($categories) < 2) {
            return [];
        }

        $presenter = app(AuditEventPresenter::class);

        $tabs = [
            'all' => Tab::make(__('All')),
        ];

        foreach ($categories as $category) {
            $classes = $presenter->classesForCategory($category->value);

            $tabs[$category->value] = Tab::make($category->label())
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->whereIn('event_class', $classes),
                );
        }

        return $tabs;
    }
}
