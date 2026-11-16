<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use App\Support\Audit\AuditEventPresenter;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAuditLog extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

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
