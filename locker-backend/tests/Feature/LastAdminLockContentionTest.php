<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\LastAdminGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LastAdminConcurrencyTest proves the invariant by replaying the competing
 * request in-process, which is all the in-memory SQLite test database allows.
 * It cannot show that a second connection is actually kept out.
 *
 * These tests open a real second PostgreSQL connection and assert the lock is
 * exclusive, so the serialization is verified rather than assumed. They are
 * skipped on any other driver.
 */
class LastAdminLockContentionTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_CONNECTION = 'pgsql_lock_probe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The advisory lock only applies to the PostgreSQL driver.');
        }

        config([
            'database.connections.'.self::PROBE_CONNECTION => config('database.connections.pgsql'),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::PROBE_CONNECTION);

        parent::tearDown();
    }

    public function test_a_second_connection_cannot_enter_while_the_guard_holds_the_lock(): void
    {
        // The guard rolls back unless an admin survives, so give it one.
        User::factory()->create()->makeAdmin();

        $lockedOut = null;

        app(LastAdminGuard::class)->protect(function () use (&$lockedOut): void {
            $lockedOut = ! $this->probeCanAcquireLock();
        });

        $this->assertTrue($lockedOut, 'A competing request must block while the guard holds the lock.');
    }

    public function test_the_lock_is_free_before_any_guarded_mutation(): void
    {
        $this->assertTrue(
            $this->probeCanAcquireLock(),
            'The probe must be able to take the lock when nothing holds it, otherwise the test above proves nothing.'
        );
    }

    /**
     * Try to take the guard's advisory lock on an independent connection with a
     * short lock_timeout. Returns false when PostgreSQL times out waiting, which
     * is the observable form of "another transaction holds this lock".
     */
    private function probeCanAcquireLock(): bool
    {
        $probe = DB::connection(self::PROBE_CONNECTION);
        $probe->statement("set lock_timeout = '250ms'");
        $probe->beginTransaction();

        try {
            $probe->select('select pg_advisory_xact_lock(?)', [LastAdminGuard::lockKey()]);

            return true;
        } catch (QueryException) {
            return false;
        } finally {
            // The transaction is aborted after a timeout, so rolling back is the
            // only way out; it also drops the lock on the success path.
            $probe->rollBack();
        }
    }
}
