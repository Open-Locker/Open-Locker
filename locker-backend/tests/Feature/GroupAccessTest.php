<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupAccessService;
use App\StorableEvents\GroupCreated;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createRegularUser(): User
    {
        User::factory()->create(); // first user may become admin automatically

        $user = User::factory()->create();
        $user->removeAdmin();

        return $user;
    }

    private function createAdminUser(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function service(): GroupAccessService
    {
        return app(GroupAccessService::class);
    }

    public function test_create_group_persists_read_model_and_event(): void
    {
        $admin = $this->createAdminUser();

        $group = $this->service()->createGroup('Engineering', 'The eng team', actor: $admin);

        $this->assertDatabaseHas('groups', [
            'id' => $group->id,
            'name' => 'Engineering',
            'created_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('stored_events', [
            'event_class' => GroupCreated::class,
        ]);
    }

    public function test_member_with_group_grant_gets_effective_row(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);

        $this->assertDatabaseHas('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
            'group_id' => $group->id,
            'expires_at' => null,
        ]);
    }

    public function test_revoking_group_grant_removes_effective_row(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);
        $this->service()->revokeCompartmentAccess($group, $compartment, actor: $admin);

        $this->assertDatabaseMissing('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
        ]);
    }

    public function test_removing_member_removes_effective_row(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);
        $this->service()->removeUser($group, $user, actor: $admin);

        $this->assertDatabaseMissing('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
        ]);
    }

    public function test_effective_expiry_is_earliest_of_membership_and_grant(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $membershipExpiry = now()->addDays(2)->startOfSecond();
        $grantExpiry = now()->addDays(10)->startOfSecond();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, expiresAt: $membershipExpiry, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, expiresAt: $grantExpiry, actor: $admin);

        $row = \App\Models\UserGroupCompartmentAccess::query()
            ->where('user_id', $user->id)
            ->where('compartment_id', (string) $compartment->id)
            ->firstOrFail();

        $this->assertTrue($membershipExpiry->equalTo($row->expires_at), 'effective expiry should be the earlier (membership) expiry');
    }

    public function test_access_via_two_groups_survives_revoking_one(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $groupA = $this->service()->createGroup('A', actor: $admin);
        $groupB = $this->service()->createGroup('B', actor: $admin);

        foreach ([$groupA, $groupB] as $group) {
            $this->service()->addUser($group, $user, actor: $admin);
            $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);
        }

        // Revoke via group A only; group B still grants it.
        $this->service()->revokeCompartmentAccess($groupA, $compartment, actor: $admin);

        $this->assertDatabaseHas('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
        ]);
        $this->assertDatabaseCount('user_group_compartment_accesses', 1);
    }

    public function test_non_admin_cannot_manage_groups(): void
    {
        $user = $this->createRegularUser();

        $this->expectException(AuthorizationException::class);
        $this->service()->createGroup('Nope', actor: $user);
    }

    public function test_duplicate_grant_keeps_single_group_grant_row(): void
    {
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);

        $this->assertDatabaseCount('group_compartment_accesses', 1);
    }

    // Archiving (event-sourced, not delete): see ADR-0020 / #106.

    public function test_archive_group_sets_archived_at_and_keeps_membership_and_grant_rows(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);

        $this->service()->archiveGroup(Group::find($group->id), actor: $admin);

        $this->assertDatabaseHas('groups', ['id' => $group->id, 'archived_by_user_id' => $admin->id]);
        $this->assertNotNull(Group::find($group->id)->archived_at);

        // Membership and grant history are preserved, not revoked or deleted.
        $this->assertDatabaseHas('group_user', [
            'group_id' => $group->id,
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('group_compartment_accesses', [
            'group_id' => $group->id,
            'compartment_id' => (string) $compartment->id,
            'revoked_at' => null,
        ]);
    }

    public function test_archiving_group_removes_effective_access_when_it_was_the_only_source(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $group = $this->service()->createGroup('Team', actor: $admin);
        $this->service()->addUser($group, $user, actor: $admin);
        $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);

        $this->service()->archiveGroup(Group::find($group->id), actor: $admin);

        $this->assertDatabaseMissing('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
        ]);
    }

    public function test_archiving_group_with_overlapping_access_keeps_effective_access_from_other_group(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $groupA = $this->service()->createGroup('A', actor: $admin);
        $groupB = $this->service()->createGroup('B', actor: $admin);

        foreach ([$groupA, $groupB] as $group) {
            $this->service()->addUser($group, $user, actor: $admin);
            $this->service()->grantCompartmentAccess($group, $compartment, actor: $admin);
        }

        // Archive group A only; group B still grants it, so the effective row must survive.
        $this->service()->archiveGroup(Group::find($groupA->id), actor: $admin);

        $this->assertDatabaseHas('user_group_compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
            'group_id' => (string) $groupB->id,
        ]);
        $this->assertDatabaseCount('user_group_compartment_accesses', 1);
    }

    public function test_non_admin_cannot_archive_group(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $group = $this->service()->createGroup('Team', actor: $admin);

        $this->expectException(AuthorizationException::class);
        $this->service()->archiveGroup(Group::find($group->id), actor: $user);
    }
}
