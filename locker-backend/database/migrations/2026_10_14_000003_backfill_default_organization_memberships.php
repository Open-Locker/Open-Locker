<?php

declare(strict_types=1);

use App\Aggregates\OrganizationMembershipAggregate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add every existing user to the default organization through the
     * event-sourced membership flow (ADR-0067), so a replay rebuilds the
     * memberships. They joined when their account was created.
     */
    public function up(): void
    {
        $organizationId = (int) DB::table('organizations')->where('slug', 'default')->value('id');

        DB::table('users')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($organizationId): void {
                foreach ($users as $user) {
                    OrganizationMembershipAggregate::retrieve(
                        OrganizationMembershipAggregate::aggregateUuidFor($organizationId, (int) $user->id)
                    )
                        ->addMember(
                            organizationId: $organizationId,
                            userId: (int) $user->id,
                            actorUserId: null,
                            joinedAt: $user->created_at !== null ? Carbon::parse($user->created_at) : now(),
                        )
                        ->persist();
                }
            });
    }

    /**
     * Remove the memberships through events as well; otherwise the aggregates
     * would still count everyone as a member, and a later `up` would record
     * nothing into the recreated, empty read model.
     */
    public function down(): void
    {
        DB::table('organization_user')
            ->select(['organization_id', 'user_id'])
            ->orderBy('organization_id')
            ->orderBy('user_id')
            ->get()
            ->each(function ($membership): void {
                OrganizationMembershipAggregate::retrieve(
                    OrganizationMembershipAggregate::aggregateUuidFor((int) $membership->organization_id, (int) $membership->user_id)
                )
                    ->removeMember(
                        organizationId: (int) $membership->organization_id,
                        userId: (int) $membership->user_id,
                        actorUserId: null,
                        removedAt: now(),
                    )
                    ->persist();
            });
    }
};
