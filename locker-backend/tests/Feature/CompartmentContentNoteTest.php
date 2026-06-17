<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\CompartmentService;
use App\StorableEvents\CompartmentContentNoteUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompartmentContentNoteTest extends TestCase
{
    use RefreshDatabase;

    private function createRegularUser(): User
    {
        User::factory()->create(); // first user may become admin automatically

        $user = User::factory()->create();
        $user->is_admin_since = null;
        $user->save();

        return $user;
    }

    private function createAdminUser(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_user_with_access_can_update_note(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => 'Winter tires (set of 4)']
        );

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('compartment_id', (string) $compartment->id)
            ->assertJsonPath('content_note', 'Winter tires (set of 4)')
            ->assertJsonPath('content_note_updated_by_user_id', $user->id);

        $this->assertDatabaseHas('compartments', [
            'id' => (string) $compartment->id,
            'content_note' => 'Winter tires (set of 4)',
            'content_note_updated_by_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => CompartmentContentNoteUpdated::class,
        ]);
    }

    public function test_admin_can_update_note_without_explicit_access(): void
    {
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        $response = $this->actingAs($admin)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => 'Spare keys']
        );

        $response->assertStatus(200)
            ->assertJsonPath('content_note', 'Spare keys')
            ->assertJsonPath('content_note_updated_by_user_id', $admin->id);
    }

    public function test_user_without_access_cannot_update_note(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => 'Should be rejected']
        );

        $response->assertStatus(403);

        $this->assertDatabaseMissing('stored_events', [
            'event_class' => CompartmentContentNoteUpdated::class,
        ]);
    }

    public function test_blank_note_clears_the_note(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);
        app(CompartmentService::class)->updateContentNote($user, $compartment, 'Initial note');

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => '   ']
        );

        $response->assertStatus(200)
            ->assertJsonPath('content_note', null);

        $this->assertDatabaseHas('compartments', [
            'id' => (string) $compartment->id,
            'content_note' => null,
        ]);
    }

    public function test_note_is_trimmed(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => '  padded  ']
        );

        $response->assertStatus(200)
            ->assertJsonPath('content_note', 'padded');
    }

    public function test_note_at_max_length_is_accepted(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $note = str_repeat('a', 80);

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => $note]
        );

        $response->assertStatus(200)
            ->assertJsonPath('content_note', $note);
    }

    public function test_note_over_max_length_is_rejected(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => str_repeat('a', 81)]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $this->assertDatabaseMissing('stored_events', [
            'event_class' => CompartmentContentNoteUpdated::class,
        ]);
    }

    public function test_note_appears_in_accessible_response(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);
        app(CompartmentService::class)->updateContentNote($user, $compartment, 'Books and cables');

        $response = $this->actingAs($user)->getJson(route('compartments.accessible'));

        $response->assertStatus(200)
            ->assertJsonPath('locker_banks.0.compartments.0.content_note', 'Books and cables');
    }

    public function test_unverified_user_cannot_update_note(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);
        $user->forceFill(['email_verified_at' => null])->save();

        $response = $this->actingAs($user)->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => 'Should be blocked']
        );

        $response->assertStatus(403);

        $this->assertDatabaseMissing('stored_events', [
            'event_class' => CompartmentContentNoteUpdated::class,
        ]);
    }

    public function test_unauthenticated_user_cannot_update_note(): void
    {
        $compartment = Compartment::factory()->create();

        $response = $this->putJson(
            route('compartments.content-note.update', $compartment->id),
            ['note' => 'Nope']
        );

        $response->assertStatus(401);
    }
}
