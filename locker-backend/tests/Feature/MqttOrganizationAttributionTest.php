<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\Services\LockerProvisioningService;
use App\StorableEvents\CompartmentUncommandedOpenDetected;
use App\StorableEvents\DeviceEventReceived;
use App\Support\EventSourcing\OrganizationStamp;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * Hardware belongs to whoever owns the bank, and the listener has to say so.
 *
 * The MQTT listener is a long-running process with no request behind it, so
 * nothing declares an organization the way middleware does. Left alone, every
 * device event stores unstamped and reads back as the default organization —
 * which means one operator's door jams reach another operator's managers and
 * never reach their own, and their audit log stays silent.
 */
class MqttOrganizationAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_an_event_derived_from_a_device_event_keeps_its_organization(): void
    {
        $beta = Organization::create(['name' => 'Beta Operator', 'slug' => 'beta']);

        $compartment = app(OrganizationContext::class)->runWithin($beta, function (): Compartment {
            $bank = LockerBank::create(['name' => 'Beta Bank']);

            return Compartment::create([
                'locker_bank_id' => $bank->id,
                'number' => 1,
                'slave_id' => 1,
                'address' => 0,
            ]);
        });

        app(OrganizationContext::class)->set(null);

        app(DeviceEventHandler::class)->handleMessage(
            "locker/{$compartment->locker_bank_id}/event",
            (string) json_encode([
                'message_id' => '55555555-5555-5555-5555-555555555555',
                'event' => 'compartment_uncommanded_open',
                'event_id' => '66666666-6666-6666-6666-666666666666',
                'timestamp' => now()->toIso8601String(),
                'data' => ['compartment_number' => 1, 'milliseconds_since_last_relay_fire' => 90000],
            ]),
        );

        $derived = EloquentStoredEvent::query()
            ->where('event_class', CompartmentUncommandedOpenDetected::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($derived, 'An uncommanded open should record a deviation event.');

        // The deviation reactor picks its recipients from this stamp. Unstamped,
        // it reads as the default organization — so one operator's jammed door
        // alerts another operator's managers and never reaches its own.
        $this->assertSame($beta->id, $derived->meta_data[OrganizationStamp::KEY] ?? null);
    }

    public function test_a_device_can_provision_itself_without_an_organization_in_context(): void
    {
        // Provisioning replies and pushes the config over MQTT; CI has no broker.
        $this->mock(ProvisioningReplyPublisher::class)->shouldIgnoreMissing();
        $this->mock(ApplyConfigCommandPublisher::class)->shouldIgnoreMissing();
        $beta = Organization::create(['name' => 'Beta Operator', 'slug' => 'beta']);

        $bank = app(OrganizationContext::class)->runWithin(
            $beta,
            fn (): LockerBank => LockerBank::create(['name' => 'Beta Bank']),
        );

        $actor = User::factory()->create();
        UserRole::create([
            'user_id' => $actor->id,
            'organization_id' => $beta->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        $token = app(OrganizationContext::class)->runWithin(
            $beta,
            fn (): string => app(LockerProvisioningService::class)->restart($bank, $actor),
        );

        // Registration names no bank, so nothing has established an
        // organization — the token lookup is what establishes it. Scoped, it
        // matches nothing and every device in every installation is told its
        // token is invalid.
        app(OrganizationContext::class)->set(null);

        $this->assertTrue(
            app(LockerProvisioningService::class)->acceptRegistration($token, 'locker/register/reply'),
            'A device must be able to provision itself with no organization resolved yet.',
        );
    }

    public function test_a_device_event_is_attributed_to_the_bank_owners_organization(): void
    {
        $beta = Organization::create(['name' => 'Beta Operator', 'slug' => 'beta']);

        $bank = app(OrganizationContext::class)->runWithin(
            $beta,
            fn (): LockerBank => LockerBank::create(['name' => 'Beta Bank']),
        );

        // Exactly how the listener runs: no organization in context at all.
        app(OrganizationContext::class)->set(null);

        app(DeviceEventHandler::class)->handleMessage(
            "locker/{$bank->id}/event",
            (string) json_encode([
                'message_id' => '33333333-3333-3333-3333-333333333333',
                'event' => 'door_opened',
                'event_id' => '44444444-4444-4444-4444-444444444444',
                'timestamp' => now()->toIso8601String(),
                'data' => ['compartment_id' => 7, 'source' => 'sensor'],
            ]),
        );

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', DeviceEventReceived::class)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            $beta->id,
            $storedEvent->meta_data[OrganizationStamp::KEY] ?? null,
            "A bank's events belong to the operator that owns it, not to the default organization.",
        );
    }
}
