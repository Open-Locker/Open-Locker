<?php

declare(strict_types=1);

namespace App\Support\EventSourcing;

use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class StoredEventDispatcher
{
    public function dispatch(ShouldBeStored $event): void
    {
        OrganizationStamp::apply($event);

        DB::transaction(static function () use ($event): void {
            event($event);
        });
    }
}
