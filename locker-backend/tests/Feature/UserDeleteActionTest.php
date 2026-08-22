<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\LastAdminGuard;
use Closure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the panel wiring rather than the invariant itself: the delete actions
 * hand their records to UserAdministrationService, and a refusal from the guard
 * surfaces as a failed action instead of a silent success.
 */
class UserDeleteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_action_removes_a_user_through_the_service(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertNull(User::find($target->id));
    }

    public function test_delete_action_refuses_when_the_guard_loses_the_race(): void
    {
        $admin = $this->admin();
        $target = $this->admin();

        // Both admins exist when the action's own pre-check runs, so the request
        // gets as far as the guard before the competing demotion lands.
        $this->whenLockAcquired(fn () => $admin->removeAdmin());

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->callAction('delete');

        $this->assertNotNull(User::find($target->id), 'The last admin must survive a lost race.');
    }

    public function test_bulk_delete_action_removes_users_through_the_service(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create();
        $second = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->set('selectedTableRecords', [(string) $first->id, (string) $second->id])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertNull(User::find($first->id));
        $this->assertNull(User::find($second->id));
    }

    public function test_bulk_delete_action_keeps_the_whole_selection_when_no_admin_would_remain(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->set('selectedTableRecords', [(string) $admin->id, (string) $other->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotNull(User::find($admin->id));
        $this->assertNotNull(User::find($other->id));
        $this->assertSame(2, User::adminRoleCount());
    }

    /**
     * The test above is stopped by the action's own pre-check. This one lets the
     * selection past that check so the refusal has to come from the guard, and
     * takes a plain user along to prove the rollback covers the whole selection.
     */
    public function test_bulk_delete_action_rolls_back_the_selection_when_the_guard_loses_the_race(): void
    {
        $admin = $this->admin();
        $target = $this->admin();
        $bystander = User::factory()->create();

        $this->whenLockAcquired(fn () => $admin->removeAdmin());

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->set('selectedTableRecords', [(string) $target->id, (string) $bystander->id])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotNull(User::find($target->id), 'The last admin must survive a lost race.');
        $this->assertNotNull($bystander->fresh(), 'The rest of the selection must roll back with it.');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    /**
     * @see LastAdminConcurrencyTest for why the competing request is replayed
     *      at lock acquisition rather than run in parallel.
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
}
