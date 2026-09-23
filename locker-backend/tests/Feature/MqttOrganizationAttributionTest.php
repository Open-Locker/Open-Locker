<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Services\LockerProvisioningService;
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

    public function test_a_device_can_provision_itself_without_an_organization_in_context(): void
    {
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
