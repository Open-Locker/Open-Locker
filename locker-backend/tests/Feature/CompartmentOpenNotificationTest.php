<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CompartmentOpenStatusUpdated;
use App\Filament\Resources\CompartmentResource\Pages\ListCompartments;
use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentAccessService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use PhpMqtt\Client\Facades\MQTT;
use RuntimeException;
use Tests\Fakes\FakeMqttClient;
use Tests\TestCase;

class CompartmentOpenNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_denied_open_notifies_without_exposing_command_id(): void
    {
        $admin = User::factory()->unverified()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->callAction(TestAction::make('open')->table($compartment))
            ->assertNotified(__('Open command denied'));
    }

    public function test_authorized_open_sends_no_synchronous_notification(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();

        MQTT::shouldReceive('connection')
            ->once()
            ->with('publisher')
            ->andReturn(new FakeMqttClient);

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->callAction(TestAction::make('open')->table($compartment))
            ->assertNotNotified();
    }

    public function test_open_failure_shows_generic_error_without_exception_details(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $compartment = Compartment::factory()->create();

        $this->partialMock(
            CompartmentAccessService::class,
            fn (MockInterface $mock) => $mock
                ->shouldReceive('requestOpen')
                ->once()
                ->andThrow(new RuntimeException('internal database detail')),
        );

        Livewire::actingAs($admin)
            ->test(ListCompartments::class)
            ->callAction(TestAction::make('open')->table($compartment))
            ->assertNotified(__('Failed to send open command'));
    }

    public function test_broadcast_payload_keeps_original_keys_and_adds_compartment_context(): void
    {
        $event = new CompartmentOpenStatusUpdated(
            userId: 7,
            commandId: 'cmd-1',
            compartmentUuid: 'uuid-1',
            status: 'opened',
            errorCode: null,
            message: null,
            compartmentNumber: 3,
            lockerName: 'Main entrance',
        );

        $this->assertSame([
            'command_id' => 'cmd-1',
            'compartment_id' => 'uuid-1',
            'status' => 'opened',
            'error_code' => null,
            'message' => null,
            'compartment_number' => 3,
            'locker_name' => 'Main entrance',
        ], $event->broadcastWith());
    }
}
