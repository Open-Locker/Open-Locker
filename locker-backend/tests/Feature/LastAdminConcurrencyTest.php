<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\LastAdminGuard;
use App\Services\UserAdministrationService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * The single-request last-admin guards are covered elsewhere. These tests cover
 * the interleaving that defeats a plain check-then-act: a second request that
 * removes the other administrator between our check and our commit.
 *
 * Real threads cannot be used against the in-memory SQLite test database, so
 * the competing request is replayed at the exact point where it matters — while
 * this request is acquiring the serialization lock.
 */
class LastAdminConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_demotion_is_refused_when_the_other_admin_is_removed_concurrently(): void
    {
        [$actor, $target] = $this->twoAdmins();

        $this->whenLockAcquired(fn () => $this->stripAdmin($actor));

        $changed = app(UserAdministrationService::class)->changeRole($actor, $target, Role::User);

        $this->assertFalse($changed, 'The demotion should lose the race and be refused.');
        $this->assertTrue($target->fresh()->isAdmin());
        $this->assertGreaterThanOrEqual(1, User::adminRoleCount());
    }

    public function test_deletion_is_refused_when_the_other_admin_is_removed_concurrently(): void
    {
        [$actor, $target] = $this->twoAdmins();

        $this->whenLockAcquired(fn () => $this->stripAdmin($actor));

        $deleted = app(UserAdministrationService::class)->deleteUser($actor, $target);

        $this->assertFalse($deleted, 'The deletion should lose the race and be refused.');
        $this->assertNotNull(User::find($target->id));
        $this->assertGreaterThanOrEqual(1, User::adminRoleCount());
    }

    public function test_bulk_deletion_is_all_or_nothing(): void
    {
        [$actor, $target] = $this->twoAdmins();

        $deleted = app(UserAdministrationService::class)->deleteUsers($actor, [$target, $actor]);

        $this->assertFalse($deleted, 'Deleting every admin should be refused as a whole.');
        $this->assertNotNull(User::find($target->id), 'The partially applied deletions must roll back.');
        $this->assertNotNull(User::find($actor->id));
        $this->assertSame(2, User::adminRoleCount());
    }

    public function test_a_losing_role_change_leaves_no_stored_events_behind(): void
    {
        [$actor, $target] = $this->twoAdmins();

        $before = $this->storedEventCount();

        $this->whenLockAcquired(fn () => $this->stripAdmin($actor));
        app(UserAdministrationService::class)->changeRole($actor, $target, Role::User);

        $this->assertSame($before, $this->storedEventCount(), 'A refused change must not persist a revocation event.');
    }

    public function test_a_non_conflicting_demotion_still_succeeds(): void
    {
        [$actor, $target] = $this->twoAdmins();

        $this->assertTrue(app(UserAdministrationService::class)->changeRole($actor, $target, Role::User));
        $this->assertFalse($target->fresh()->isAdmin());
        $this->assertSame(1, User::adminRoleCount());
    }

    /**
     * The minimum setup for a race: the actor and one other administrator, so
     * that removing both leaves none.
     *
     * @return array{User, User}
     */
    private function twoAdmins(): array
    {
        $users = [];

        for ($i = 0; $i < 2; $i++) {
            $user = User::factory()->create();
            $user->makeAdmin();
            $users[] = $user;
        }

        return $users;
    }

    /**
     * Stand in for a competing request that has already committed its own
     * admin removal by the time this request gets the lock.
     */
    private function whenLockAcquired(Closure $competingRequest): void
    {
        $this->app->instance(LastAdminGuard::class, new class($competingRequest) extends LastAdminGuard
        {
            public function __construct(private ?Closure $competingRequest) {}

            protected function acquireLock(): void
            {
                parent::acquireLock();

                $competingRequest = $this->competingRequest;
                $this->competingRequest = null;

                ($competingRequest)();
            }
        });
    }

    private function stripAdmin(User $user): void
    {
        $user->removeAdmin();
    }

    private function storedEventCount(): int
    {
        return EloquentStoredEvent::query()->count();
    }
}
