<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\Support\EventSourcing\OrganizationStamp;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

abstract class TransactionalAggregateRoot extends AggregateRoot
{
    /**
     * Every event records where it happened. Stamped here rather than on each
     * event class so a new one cannot forget, and so the organization stays
     * context about the event rather than part of its payload.
     */
    public function recordThat(ShouldBeStored $domainEvent): static
    {
        OrganizationStamp::apply($domainEvent);

        return parent::recordThat($domainEvent);
    }

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
