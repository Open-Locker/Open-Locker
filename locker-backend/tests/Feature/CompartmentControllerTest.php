<?php

namespace Tests\Feature;

use App\Enums\CompartmentOpenRequestStatus;
use App\Models\Compartment;
use App\Models\TermsDocumentVersion;
use App\Models\User;
use App\Services\TermsService;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpenRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compartments_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/compartments');

        $response->assertStatus(401);
    }

    public function test_the_list_reports_each_banks_connection_status(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();
        $compartment->lockerBank->forceFill([
            'connection_status' => 'offline',
            'connection_status_changed_at' => now(),
        ])->save();

        $this->actingAs($user)->getJson('/api/compartments')
            ->assertStatus(200)
            ->assertJsonPath('locker_banks.0.connection_status', 'offline');
    }

    public function test_a_bank_that_never_reported_is_unknown_rather_than_offline(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        // A fresh bank has never reported: the column defaults to 'unknown', which
        // is not the same as having gone offline, and the app colours the two
        // differently.
        Compartment::factory()->create();

        $this->actingAs($user)->getJson('/api/compartments')
            ->assertStatus(200)
            ->assertJsonPath('locker_banks.0.connection_status', 'unknown');
    }

    public function test_compartments_endpoint_returns_compartments_with_contents(): void
    {
        // Sees every compartment because they are an admin, which this test used to
        // get implicitly from being the first user created.
        $user = User::factory()->create();
        $user->makeAdmin();

        /** @var Compartment $firstCompartment */
        $firstCompartment = Compartment::factory()->create();

        /** @var Compartment $secondCompartment */
        $secondCompartment = Compartment::factory()->create([
            'locker_bank_id' => $firstCompartment->locker_bank_id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/compartments');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'locker_banks')
            ->assertJsonCount(2, 'locker_banks.0.compartments');

        $response->assertJsonStructure([
            'locker_banks' => [[
                'id',
                'name',
                'location_description',
                'connection_status',
                'compartments' => [[
                    'id',
                    'number',
                    'content_note',
                ]],
            ]],
        ]);

        $response->assertJsonMissingPath('locker_banks.0.compartments.0.item');

        $response->assertJsonFragment([
            'id' => (string) $secondCompartment->id,
            'content_note' => null,
        ]);
    }

    /**
     * Refusing an open attempt used to happen in middleware, which answered
     * before the controller ran and so left no trace of the attempt anywhere.
     * The attempt must now be visible in the event stream and in the
     * compartment's open history, without ever becoming authorized.
     */
    public function test_an_open_attempt_before_the_terms_are_accepted_is_recorded_and_refused(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        // Admins are otherwise always authorized, so the refusal can only be
        // the outstanding terms.
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();
        app(TermsService::class)->publishNewVersion('AGB', '<p>Version 1</p>', $user);

        $this->actingAs($user)
            ->postJson('/api/compartments/'.$compartment->id.'/open')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'You must accept the latest terms before continuing.',
                'code' => 'terms_not_accepted',
                'terms_current_version' => 1,
            ]);

        $this->assertTrue(
            EloquentStoredEvent::query()->where('event_class', CompartmentOpenRequested::class)->exists()
        );
        $this->assertFalse(
            EloquentStoredEvent::query()->where('event_class', CompartmentOpenAuthorized::class)->exists()
        );

        $denial = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenDenied::class)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('terms_not_accepted', $denial->event_properties['reason'] ?? null);

        $this->assertDatabaseHas('compartment_open_requests', [
            'actor_user_id' => $user->id,
            'compartment_id' => $compartment->id,
            'status' => CompartmentOpenRequestStatus::Denied->value,
            'denied_reason' => 'terms_not_accepted',
        ]);
    }

    public function test_an_open_attempt_with_an_unverified_email_is_recorded_and_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/compartments/'.$compartment->id.'/open')
            ->assertStatus(403)
            ->assertExactJson([
                'status' => false,
                'message' => 'Please verify your email address before opening compartments',
            ]);

        $this->assertFalse(
            EloquentStoredEvent::query()->where('event_class', CompartmentOpenAuthorized::class)->exists()
        );

        $denial = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenDenied::class)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('unverified_email', $denial->event_properties['reason'] ?? null);

        $this->assertDatabaseHas('compartment_open_requests', [
            'actor_user_id' => $user->id,
            'compartment_id' => $compartment->id,
            'status' => CompartmentOpenRequestStatus::Denied->value,
            'denied_reason' => 'unverified_email',
        ]);
    }

    /**
     * The middleware ran terms-first, and the mobile app steers on that body,
     * so a user who trips both still has to be told about the terms.
     */
    public function test_outstanding_terms_outrank_an_unverified_email(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();
        app(TermsService::class)->publishNewVersion('AGB', '<p>Version 1</p>', $user);

        $this->actingAs($user)
            ->postJson('/api/compartments/'.$compartment->id.'/open')
            ->assertStatus(403)
            ->assertJsonPath('code', 'terms_not_accepted');

        $denial = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenDenied::class)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('terms_not_accepted', $denial->event_properties['reason'] ?? null);
    }

    /**
     * Only the open route decides the terms itself; lifting the gate there must
     * not lift it from its neighbours in the same route group.
     */
    public function test_the_other_compartment_routes_are_still_gated_by_the_terms_middleware(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();
        app(TermsService::class)->publishNewVersion('AGB', '<p>Version 1</p>', $user);

        $this->actingAs($user)
            ->putJson('/api/compartments/'.$compartment->id.'/content-note', ['note' => 'Skis'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'terms_not_accepted');

        $this->assertFalse(
            EloquentStoredEvent::query()->where('event_class', CompartmentOpenRequested::class)->exists()
        );
    }

    /**
     * The open route and the terms middleware have to agree on what "accepted"
     * means. They only do if both match the acceptance against the active
     * version's id: after a rollback the user's most recent acceptance is for a
     * version that is no longer in force, even though it is the newer one.
     */
    public function test_an_acceptance_of_a_rolled_back_later_version_does_not_count(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();

        $terms = app(TermsService::class);
        $firstVersion = $terms->publishNewVersion('AGB', '<p>Version 1</p>', $user);
        $secondVersion = $terms->publishNewVersion('AGB', '<p>Version 2</p>', $user);
        $terms->acceptCurrentTerms($user);

        TermsDocumentVersion::query()->whereKey($secondVersion->id)->update(['is_active' => false]);
        TermsDocumentVersion::query()->whereKey($firstVersion->id)->update(['is_active' => true]);

        // The open route decides this itself...
        $this->actingAs($user)
            ->postJson('/api/compartments/'.$compartment->id.'/open')
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'You must accept the latest terms before continuing.',
                'code' => 'terms_not_accepted',
                'terms_current_version' => 1,
            ]);

        // ...and the middleware, on a route it still guards, agrees.
        $this->actingAs($user)
            ->putJson('/api/compartments/'.$compartment->id.'/content-note', ['note' => 'Skis'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'terms_not_accepted');
    }
}
