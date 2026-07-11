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
     * log, per ADR-0026. "All" is the default tab.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $presenter = app(AuditEventPresenter::class);

        $tabs = [
            'all' => Tab::make(__('All')),
        ];

        foreach ($presenter->categories() as $key => $label) {
            $classes = $presenter->classesForCategory($key);

            $tabs[$key] = Tab::make($label)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->whereIn('event_class', $classes),
                );
        }

        return $tabs;
    }
}
