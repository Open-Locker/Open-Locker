<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\CompartmentDoorStateUpdated;
use App\Events\LockerBankConnectionUpdated;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\CompartmentHelpRequestedNotification;
use App\Reactors\CompartmentDoorStateBroadcastReactor;
use App\Reactors\CompartmentHelpRequestAlertReactor;
use App\Reactors\LockerBankConnectionBroadcastReactor;
use App\StorableEvents\CompartmentDoorStateChanged;
use App\StorableEvents\CompartmentHelpRequested;
use App\StorableEvents\LockerConnectionLost;
use App\Support\EventSourcing\OrganizationStamp;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Status broadcasts run in queued reactors, where no organization is in context.
 * Every other test resolves one before the reactor runs, which is how recipients
 * came out wrong without anything failing: access holders were dropped and
 * operators of every organization were included.
 */
class QueuedStatusBroadcastOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private LockerBank $lockerBank;

    private Compartment $compartment;

    private User $accessHolder;

    private User $operator;

    private User $otherOrganizationsOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $rival = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);

        $this->lockerBank = LockerBank::factory()->create();
        $this->compartment = Compartment::factory()->for($this->lockerBank)->create();

        $this->accessHolder = User::factory()->create();
        CompartmentAccess::factory()->create([
            'compartment_id' => $this->compartment->id,
            'user_id' => $this->accessHolder->id,
        ]);

        $this->operator = $this->givenManagerIn((string) DefaultOrganization::id());
        $this->otherOrganizationsOperator = $this->givenManagerIn($rival->id);

        // What the queue worker sees.
        app(OrganizationContext::class)->set(null);
    }

    public function test_door_state_reaches_this_organizations_people_only(): void
    {
        Event::fake([CompartmentDoorStateUpdated::class]);

        app(CompartmentDoorStateBroadcastReactor::class)->onCompartmentDoorStateChanged(new CompartmentDoorStateChanged(
            lockerBankUuid: (string) $this->lockerBank->id,
            compartmentUuid: (string) $this->compartment->id,
            compartmentNumber: 1,
            previousDoorState: 'closed',
            newDoorState: 'open',
            doorStateChangedAtIso8601: now()->toIso8601String(),
            mqttMessageId: 'message-1',
        ));

        Event::assertDispatched(
            CompartmentDoorStateUpdated::class,
            fn (CompartmentDoorStateUpdated $event): bool => $this->reachesThisOrganizationOnly($event->recipientUserIds),
        );
    }

    public function test_bank_connection_reaches_this_organizations_people_only(): void
    {
        Event::fake([LockerBankConnectionUpdated::class]);

        app(LockerBankConnectionBroadcastReactor::class)->onLockerConnectionLost(new LockerConnectionLost(
            lockerBankUuid: (string) $this->lockerBank->id,
            detectedAtIso8601: now()->toIso8601String(),
        ));

        Event::assertDispatched(
            LockerBankConnectionUpdated::class,
            fn (LockerBankConnectionUpdated $event): bool => $this->reachesThisOrganizationOnly($event->recipientUserIds),
        );
    }

    public function test_help_requests_reach_this_organizations_operators_only(): void
    {
        Notification::fake();
        $event = new CompartmentHelpRequested(
            helpRequestUuid: 'help-1',
            compartmentUuid: (string) $this->compartment->id,
            actorUserId: $this->accessHolder->id,
            message: 'Door is stuck.',
            requestedAtIso8601: now()->toIso8601String(),
        );
        $event->setMetaData([OrganizationStamp::KEY => (string) DefaultOrganization::id()]);

        app(CompartmentHelpRequestAlertReactor::class)->onCompartmentHelpRequested($event);

        Notification::assertSentTo(
            $this->operator,
            CompartmentHelpRequestedNotification::class,
            // Names the bank, which a scoped lookup in a queued job cannot find.
            fn (CompartmentHelpRequestedNotification $notification): bool => str_contains(
                (string) $notification->toMail($this->operator)->subject,
                $this->lockerBank->name,
            ),
        );
        Notification::assertNotSentTo($this->otherOrganizationsOperator, CompartmentHelpRequestedNotification::class);
    }

    /**
     * @param  list<int>  $recipientUserIds
     */
    private function reachesThisOrganizationOnly(array $recipientUserIds): bool
    {
        return in_array($this->accessHolder->id, $recipientUserIds, true)
            && in_array($this->operator->id, $recipientUserIds, true)
            && ! in_array($this->otherOrganizationsOperator->id, $recipientUserIds, true);
    }

    private function givenManagerIn(string $organizationId): User
    {
        $user = User::factory()->create();
        UserRole::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role' => Role::Manager->value,
            'granted_at' => now(),
        ]);

        return $user;
    }
}
