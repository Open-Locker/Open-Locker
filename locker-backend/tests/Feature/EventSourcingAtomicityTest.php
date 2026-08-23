<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\TransactionalAggregateRoot;
use App\Support\EventSourcing\StoredEventDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Tests\TestCase;

class EventSourcingAtomicityTest extends TestCase
{
    public function test_aggregate_event_rolls_back_when_a_synchronous_projector_fails(): void
    {
        app(Projectionist::class)->addProjector(FailingAtomicityProjector::class);

        $thrown = false;

        try {
            AtomicityTestAggregate::retrieve('aggregate-rollback')
                ->record('aggregate')
                ->persist();
        } catch (RuntimeException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_direct_stored_event_rolls_back_when_a_synchronous_projector_fails(): void
    {
        app(Projectionist::class)->addProjector(FailingAtomicityProjector::class);

        $thrown = false;

        try {
            app(StoredEventDispatcher::class)->dispatch(new AtomicityTestEvent('direct'));
        } catch (RuntimeException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(0, EloquentStoredEvent::query()->count());
    }

    public function test_stored_event_job_is_enqueued_only_after_the_outer_transaction_commits(): void
    {
        config()->set('queue.default', 'database');
        app(Projectionist::class)->addReactor(QueuedAtomicityReactor::class);

        DB::beginTransaction();

        app(StoredEventDispatcher::class)->dispatch(new AtomicityTestEvent('commit'));

        $this->assertDatabaseCount('stored_events', 1);
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_stored_event_job_is_discarded_when_the_outer_transaction_rolls_back(): void
    {
        config()->set('queue.default', 'database');
        app(Projectionist::class)->addReactor(QueuedAtomicityReactor::class);

        DB::beginTransaction();

        app(StoredEventDispatcher::class)->dispatch(new AtomicityTestEvent('rollback'));

        $this->assertDatabaseCount('stored_events', 1);
        $this->assertDatabaseCount('jobs', 0);

        DB::rollBack();

        $this->assertDatabaseCount('stored_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}

final class AtomicityTestAggregate extends TransactionalAggregateRoot
{
    public function record(string $value): self
    {
        $this->recordThat(new AtomicityTestEvent($value));

        return $this;
    }
}

final class AtomicityTestEvent extends ShouldBeStored
{
    public function __construct(public readonly string $value) {}
}

final class FailingAtomicityProjector extends Projector
{
    public function onAtomicityTestEvent(AtomicityTestEvent $event): void
    {
        throw new RuntimeException("Projection failed for {$event->value}.");
    }
}

final class QueuedAtomicityReactor extends Reactor implements ShouldQueue
{
    public function onAtomicityTestEvent(AtomicityTestEvent $event): void {}
}
