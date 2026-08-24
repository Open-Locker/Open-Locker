<?php

declare(strict_types=1);

namespace App\Aggregates;

use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

abstract class TransactionalAggregateRoot extends AggregateRoot
{
    public function persist(): static
    {
        return DB::transaction(fn () => parent::persist());
    }

    public static function persistInTransaction(AggregateRoot ...$aggregateRoots): void
    {
        DB::transaction(static function () use ($aggregateRoots): void {
            foreach ($aggregateRoots as $aggregateRoot) {
                $aggregateRoot->persist();
            }
        });
    }
}
