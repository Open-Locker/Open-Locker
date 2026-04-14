<?php

namespace Tests\Feature;

use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\Mqtt\Publishers\ProvisioningReplyPublisher;
use App\Reactors\MqttReactor;
use App\Services\MqttUserService;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MqttReactorDelegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_compartment_event_is_delegated_to_publisher(): void
    {
        $event = new CompartmentOpeningRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            compartmentUuid: '22222222-2222-2222-2222-222222222222',
            compartmentNumber: 7,
            commandId: '33333333-3333-3333-3333-333333333333',
        );

        $this->mock(OpenCompartmentCommandPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onCompartmentOpeningRequested($event);
    }

    public function test_apply_config_event_is_delegated_to_publisher(): void
    {
        $event = new LockerConfigApplyRequested(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            commandId: '22222222-2222-2222-2222-222222222222',
            configHash: 'abc123',
            heartbeatIntervalSeconds: 15,
            compartments: [
                ['compartment_number' => 1, 'slave_id' => 1, 'address' => 0],
            ],
        );

        $this->mock(ApplyConfigCommandPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onLockerConfigApplyRequested($event);
    }

    public function test_provisioning_success_creates_user_and_delegates_reply_publish(): void
    {
        $event = new LockerWasProvisioned(
            lockerBankUuid: '11111111-1111-1111-1111-111111111111',
            replyToTopic: 'locker/provisioning/reply/test-client',
        );
        $generatedPassword = null;

        $this->mock(MqttUserService::class, function ($mock) use ($event, &$generatedPassword): void {
            $mock->shouldReceive('createUser')
                ->once()
                ->withArgs(function (string $username, string $password, string $lockerBankUuid) use ($event, &$generatedPassword): bool {
                    $generatedPassword = $password;

                    $this->assertSame($event->lockerBankUuid, $username);
                    $this->assertSame($event->lockerBankUuid, $lockerBankUuid);
                    $this->assertNotSame('', $password);

                    return true;
                });
        });

        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use ($event, &$generatedPassword): void {
            $mock->shouldReceive('publishSuccess')
                ->once()
                ->withArgs(function (LockerWasProvisioned $publishedEvent, string $mqttUser, string $mqttPassword) use ($event, &$generatedPassword): bool {
                    $this->assertSame($event, $publishedEvent);
                    $this->assertSame($event->lockerBankUuid, $mqttUser);
                    $this->assertSame($generatedPassword, $mqttPassword);

                    return true;
                });
        });

        app(MqttReactor::class)->onLockerWasProvisioned($event);
    }

    public function test_provisioning_failure_is_delegated_to_reply_publisher(): void
    {
        $event = new LockerProvisioningFailed(
            replyToTopic: 'locker/provisioning/reply/test-client',
            reason: 'Provisioning rejected',
        );

        $this->mock(ProvisioningReplyPublisher::class, function ($mock) use ($event): void {
            $mock->shouldReceive('publishFailure')
                ->once()
                ->with($event);
        });

        app(MqttReactor::class)->onLockerProvisioningFailed($event);
    }
}
