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
     * Revokes only what this migration granted, and through the same path.
     * Platform administrators appointed afterwards keep their role, and their
     * events stay consistent with the projection.
     */
    public function down(): void
    {
        foreach ($this->existingAdminIds() as $userId) {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($userId))
                ->revokeRole($userId, Role::PlatformAdmin->value, null, now(), null)
                ->persist();
        }
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
