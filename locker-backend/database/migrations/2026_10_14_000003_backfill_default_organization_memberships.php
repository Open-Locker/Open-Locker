<?php

declare(strict_types=1);

use App\Aggregates\OrganizationMembershipAggregate;
use App\Projectors\OrganizationMembershipProjector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\Projectionist;

return new class extends Migration
{
    /**
     * Add every existing user to the default organization through the
     * event-sourced membership flow (ADR-0067), so a replay rebuilds the
     * memberships. They joined when their account was created.
     *
     * The read model is then rebuilt from all membership events. After a
     * rollback, organization_user is recreated empty while the aggregates
     * still hold their members, so the backfill alone would record nothing.
     */
    public function up(): void
    {
        $organizationId = (int) DB::table('organizations')->where('slug', 'default')->value('id');

        DB::table('users')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($organizationId): void {
                foreach ($users as $user) {
                    $aggregateUuid = OrganizationMembershipAggregate::aggregateUuidFor($organizationId, (int) $user->id);

                    // Any history, including a removal, means the backfill already
                    // ran for this user; re-adding would undo the removal.
                    if (DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->exists()) {
                        continue;
                    }

                    OrganizationMembershipAggregate::retrieve($aggregateUuid)
                        ->addMember(
                            organizationId: $organizationId,
                            userId: (int) $user->id,
                            actorUserId: null,
                            joinedAt: $user->created_at !== null ? Carbon::parse($user->created_at) : now(),
                        )
                        ->persist();
                }
            });

        app(Projectionist::class)->replay(collect([app(OrganizationMembershipProjector::class)]));
    }

    /**
     * Records nothing: stored events are permanent and show in the audit log,
     * so a rollback must not invent removals. `up` restores the read model.
     */
    public function down(): void {}
};
