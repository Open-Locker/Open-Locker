<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\LastAdminException;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Serializes every mutation that can remove an administrator and enforces the
 * cross-user invariant "at least one administrator remains" as a postcondition
 * of the committed transaction.
 *
 * Checking before the mutation is not enough: two concurrent requests each read
 * a snapshot that still contains the other's administrator, both pass the check,
 * and both commit — leaving the installation with none. Holding a shared lock
 * for the whole transaction forces the second request to re-read after the first
 * has committed.
 */
class LastAdminGuard
{
    /**
     * Fixed advisory-lock key shared by every admin-role mutation. A single key
     * (rather than per-row locks) also covers the case where the rows a request
     * would have locked are the ones a concurrent request just deleted, and it
     * cannot deadlock against itself.
     */
    private const LOCK_NAME = 'open-locker:user_roles:admin';

    /**
     * Run $mutation inside the serialized transaction.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $mutation
     * @return TReturn
     *
     * @throws LastAdminException when no administrator would remain
     * @throws Throwable whatever $mutation throws
     */
    public function protect(callable $mutation): mixed
    {
        return DB::transaction(function () use ($mutation) {
            $this->acquireLock();

            $result = $mutation();

            if (User::adminRoleCount() < 1) {
                throw new LastAdminException;
            }

            return $result;
        });
    }

    /**
     * Same as protect(), but reports the violation as false instead of throwing.
     */
    public function attempt(callable $mutation): bool
    {
        try {
            $this->protect($mutation);
        } catch (LastAdminException) {
            return false;
        }

        return true;
    }

    /**
     * Transaction-scoped lock; released by the commit or rollback in protect().
     */
    protected function acquireLock(): void
    {
        $connection = DB::connection();

        match ($connection->getDriverName()) {
            'pgsql' => $connection->select('select pg_advisory_xact_lock(?)', [self::lockKey()]),
            // SQLite serializes writers process-wide, so the transaction itself
            // is the boundary. Other drivers fall back to row locks, which is
            // enough while at least one admin row exists.
            'sqlite' => null,
            default => UserRole::query()
                ->where('role', Role::Admin->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(),
        };
    }

    public static function lockKey(): int
    {
        return crc32(self::LOCK_NAME);
    }
}
