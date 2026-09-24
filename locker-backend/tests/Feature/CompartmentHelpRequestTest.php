<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\User;
use App\Notifications\CompartmentHelpRequestedNotification;
use App\Reactors\CompartmentHelpRequestAlertReactor;
use App\Services\CompartmentAccessService;
use App\Services\CompartmentService;
use App\StorableEvents\CompartmentHelpRequested;
use App\Support\Audit\AuditEventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentHelpRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_help_request_is_recorded_and_sent_to_the_operators(): void
    {
        Notification::fake();
        [$admin, $manager] = $this->givenOperators();
        $user = $this->givenRegularUser();
        $compartment = Compartment::factory()->create();
        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $response = $this->actingAs($user)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => '  Door is stuck, my bag is inside.  '],
        );

        $response->assertStatus(202)->assertJsonPath('status', true);
        $event = EloquentStoredEvent::query()
            ->where('event_class', CompartmentHelpRequested::class)
            ->sole();
        $this->assertSame([
            'helpRequestUuid' => $response->json('help_request_id'),
            'compartmentUuid' => (string) $compartment->id,
            'actorUserId' => $user->id,
            'message' => 'Door is stuck, my bag is inside.',
        ], array_intersect_key($event->event_properties, array_flip([
            'helpRequestUuid', 'compartmentUuid', 'actorUserId', 'message',
        ])));
        Notification::assertSentTo([$admin, $manager], CompartmentHelpRequestedNotification::class);
        Notification::assertNotSentTo($user, CompartmentHelpRequestedNotification::class);
    }

    public function test_a_user_without_access_to_the_compartment_is_refused(): void
    {
        Notification::fake();
        $user = $this->givenRegularUser();
        $compartment = Compartment::factory()->create();

        $this->actingAs($user)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => 'Let me in.'],
        )->assertStatus(403);

        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('event_class', CompartmentHelpRequested::class)
            ->count());
    }

    public function test_a_blank_message_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        $this->actingAs($admin)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => '   '],
        )->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_a_message_over_the_limit_is_rejected(): void
    {
        $admin = $this->givenAdmin();
        $compartment = Compartment::factory()->create();

        $this->actingAs($admin)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => str_repeat('a', CompartmentService::HELP_MESSAGE_MAX_LENGTH + 1)],
        )->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_a_call_back_phone_reaches_the_operators_email(): void
    {
        Notification::fake();
        [$admin] = $this->givenOperators();
        $compartment = Compartment::factory()->create();

        $this->actingAs($admin)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => 'Door is stuck.', 'phone' => ' +49 30 1234567 '],
        )->assertStatus(202);

        Notification::assertSentTo(
            $admin,
            CompartmentHelpRequestedNotification::class,
            fn (CompartmentHelpRequestedNotification $notification): bool => in_array(
                'Phone for a call back: +49 30 1234567',
                $notification->toMail($admin)->introLines,
                true,
            ),
        );
    }

    public function test_a_phone_that_is_not_a_number_is_rejected(): void
    {
        $admin = $this->givenAdmin();
        $compartment = Compartment::factory()->create();

        $this->actingAs($admin)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => 'Door is stuck.', 'phone' => 'call me maybe'],
        )->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_a_user_with_an_unverified_email_is_refused(): void
    {
        [$admin] = $this->givenOperators();
        $user = $this->givenRegularUser();
        $compartment = Compartment::factory()->create();
        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => 'Door is stuck.'],
        )->assertStatus(403);

        $this->assertDatabaseMissing('stored_events', [
            'event_class' => CompartmentHelpRequested::class,
        ]);
    }

    public function test_the_sixth_request_within_ten_minutes_is_throttled(): void
    {
        // TestCase switches rate limiting off for every other test.
        $this->withMiddleware(ThrottleRequests::class);
        Notification::fake();
        $admin = $this->givenAdmin();
        $compartment = Compartment::factory()->create();
        $url = route('compartments.help-requests.store', $compartment->id);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->postJson($url, ['message' => "Attempt {$i}"])->assertStatus(202);
        }

        $this->actingAs($admin)->postJson($url, ['message' => 'One too many'])->assertStatus(429);
    }

    #[DataProvider('invalidMessages')]
    public function test_the_service_refuses_an_invalid_message(string $message): void
    {
        $admin = $this->givenAdmin();
        $compartment = Compartment::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(CompartmentService::class)->requestHelp($admin, $compartment, $message);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidMessages(): array
    {
        return [
            'empty' => [''],
            'over the limit' => [str_repeat('a', CompartmentService::HELP_MESSAGE_MAX_LENGTH + 1)],
        ];
    }

    public function test_the_audit_log_shows_the_message(): void
    {
        $admin = $this->givenAdmin();
        $compartment = Compartment::factory()->create();
        app(CompartmentService::class)->requestHelp($admin, $compartment, 'Door is stuck.');

        $event = EloquentStoredEvent::query()
            ->where('event_class', CompartmentHelpRequested::class)
            ->sole();

        $this->assertStringContainsString('Door is stuck.', app(AuditEventPresenter::class)->describe($event));
    }

    public function test_the_audit_log_lists_help_requests_under_access(): void
    {
        $this->assertContains(
            CompartmentHelpRequested::class,
            app(AuditEventPresenter::class)->classesForCategory('access'),
        );
    }

    public function test_operators_are_still_emailed_when_the_user_was_deleted(): void
    {
        Notification::fake();
        [$admin] = $this->givenOperators();
        $compartment = Compartment::factory()->create();

        app(CompartmentHelpRequestAlertReactor::class)->onCompartmentHelpRequested(
            $this->helpRequestedBy(userId: 999_999, compartment: $compartment),
        );

        Notification::assertSentTo(
            $admin,
            CompartmentHelpRequestedNotification::class,
            fn (CompartmentHelpRequestedNotification $notification): bool => $notification->toMail($admin)->replyTo === [],
        );
    }

    public function test_nothing_is_sent_and_a_warning_is_logged_without_operators(): void
    {
        Notification::fake();
        Log::spy();
        $compartment = Compartment::factory()->create();

        app(CompartmentHelpRequestAlertReactor::class)->onCompartmentHelpRequested(
            $this->helpRequestedBy(userId: 999_999, compartment: $compartment),
        );

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }

    private function helpRequestedBy(int $userId, Compartment $compartment): CompartmentHelpRequested
    {
        return new CompartmentHelpRequested(
            helpRequestUuid: 'help-1',
            compartmentUuid: (string) $compartment->id,
            actorUserId: $userId,
            message: 'Door is stuck.',
            requestedAtIso8601: now()->toIso8601String(),
        );
    }

    private function givenAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function givenRegularUser(): User
    {
        User::factory()->create(); // the first user may become admin automatically

        $user = User::factory()->create();
        $user->removeAdmin();

        return $user;
    }

    /**
     * @return array{0: User, 1: User} admin, manager
     */
    private function givenOperators(): array
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, $admin->id, now())
            ->persist();

        return [$admin->fresh(), $manager->fresh()];
    }
}
