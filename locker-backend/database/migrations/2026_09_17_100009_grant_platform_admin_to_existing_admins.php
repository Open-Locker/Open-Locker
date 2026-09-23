<?php

declare(strict_types=1);

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the installation a platform administrator.
 *
 * Without this there is no supported way to create the first one: the
 * first-admin command makes an organization admin, granting platform_admin
 * requires already holding it, and creating organizations requires it too — so
 * turning on multi-organization support would leave an installation unable to
 * add a second operator.
 *
 * Whoever administered the installation before organizations existed was
 * installation-wide by definition, which is exactly what this role means now.
 *
 * Recorded through the aggregate rather than written into `user_roles`
 * directly: that table is a projection, and a row with no event behind it
 * cannot be revoked — the aggregate rebuilds its held-set from events, finds
 * nothing to revoke, and a demotion in the panel silently leaves the person a
 * platform administrator.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->existingAdminIds() as $userId) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($userId))
                ->grantRole($userId, Role::PlatformAdmin->value, null, now(), null)
                ->persist();
        }
    }

    /**
     * Rolling this back removes the role from everyone, including anyone
     * appointed after the migration ran.
     *
     * Two reasons it is not "revoke what up() granted". There is nothing to
     * distinguish those grants from later ones — platform-admin:grant records
     * an identical event — and revoking through the aggregate appends a
     * revocation rather than un-recording history, so no version of this can
     * restore the prior state anyway.
     *
     * Removing all of them is the safe reading: platform_admin exists only
     * because this migration introduced it and means nothing without
     * multi-organization support, so abandoning the feature should not leave
     * installation-wide access behind.
     */
    public function down(): void
    {
        foreach ($this->platformAdminIds() as $userId) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($userId))
                ->revokeRole($userId, Role::PlatformAdmin->value, null, now(), null)
                ->persist();
        }
    }

    /**
     * @return list<int>
     */
    private function platformAdminIds(): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('user_roles')
            ->where('role', Role::PlatformAdmin->value)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function existingAdminIds(): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('user_roles')
            ->where('role', Role::Admin->value)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $ids;
    }
};
