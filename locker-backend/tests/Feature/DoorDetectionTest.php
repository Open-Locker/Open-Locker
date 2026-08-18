<?php

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\CompartmentOpenRequestStatus;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\CompartmentOpenRequest;
use App\Models\LockerBank;
use App\Models\User;
use App\Mqtt\Publishers\OpenCompartmentCommandPublisher;
use App\Notifications\Security\CompartmentOpenDeviationNotification;
use App\StorableEvents\CompartmentDoorAlreadyOpen;
use App\StorableEvents\CompartmentDoorOpenDetected;
use App\StorableEvents\CompartmentOpenAcknowledged;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\CompartmentOpenNotDetected;
use App\StorableEvents\CompartmentUncommandedOpenDetected;
use App\StorableEvents\DeviceEventReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * Door-open detection derived from the device event channel.
 */
class DoorDetectionTest extends TestCase
{
    use RefreshDatabase;

    private LockerBank $lockerBank;

    private Compartment $compartment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(OpenCompartmentCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->zeroOrMoreTimes();
        });

        $this->lockerBank = LockerBank::factory()->create();
        $this->compartment = Compartment::factory()->create([
            'locker_bank_id' => $this->lockerBank->id,
            'number' => 7,
        ]);
    }

    public function test_a_successful_open_alerts_nobody(): void
    {
        Notification::fake();
        $this->givenOperatorsHoldingCompartmentOpen();

        $transactionId = 'txn-quiet';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'opened',
            'detection_ms' => 300,
        ]);

        Notification::assertNothingSent();
    }

    public function test_open_detected_event_records_the_door_as_opened(): void
    {
        $transactionId = 'txn-opened';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'opened',
            'detection_ms' => 502,
        ]);

        $this->assertSame(1, $this->derivedCount(CompartmentDoorOpenDetected::class, $transactionId));

        $request = CompartmentOpenRequest::query()->find($transactionId);
        $this->assertNotNull($request);
        $this->assertSame(CompartmentOpenRequestStatus::Opened, $request->status);
        $this->assertSame(502, $request->open_detection_ms);
        $this->assertNotNull($request->opened_at);
    }

    public function test_already_open_is_recorded_as_its_own_deviation(): void
    {
        Notification::fake();
        $this->givenOperatorsHoldingCompartmentOpen();

        $transactionId = 'txn-already';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'already_open',
        ]);

        $this->assertSame(1, $this->derivedCount(CompartmentDoorAlreadyOpen::class, $transactionId));
        $this->assertSame(0, $this->derivedCount(CompartmentDoorOpenDetected::class, $transactionId));

        $status = CompartmentOpenRequest::query()->find($transactionId)?->status;
        $this->assertSame(CompartmentOpenRequestStatus::AlreadyOpen, $status);

        Notification::assertSentTimes(CompartmentOpenDeviationNotification::class, 2);
    }

    public function test_a_jammed_door_is_recorded_as_a_failure_not_a_success(): void
    {
        Notification::fake();
        $this->givenOperatorsHoldingCompartmentOpen();

        $transactionId = 'txn-jammed';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_failed', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'door_jammed',
            'error_code' => 'DOOR_JAMMED',
        ]);

        $this->assertSame(1, $this->derivedCount(CompartmentOpenNotDetected::class, $transactionId));

        $request = CompartmentOpenRequest::query()->find($transactionId);
        $this->assertSame(CompartmentOpenRequestStatus::DoorJammed, $request?->status);
        $this->assertSame('DOOR_JAMMED', $request?->error_code);
        $this->assertNotNull($request?->failed_at);

        // Every deviation alerts the same way, jams included.
        Notification::assertSentTimes(CompartmentOpenDeviationNotification::class, 2);
    }

    public function test_detection_derivation_is_idempotent(): void
    {
        $transactionId = 'txn-repeat';
        $this->givenAnOpenCommandWasSent($transactionId);

        foreach (range(1, 3) as $ignored) {
            $this->receiveDeviceEvent('compartment_open_detected', [
                'compartment_number' => 7,
                'transaction_id' => $transactionId,
                'outcome' => 'opened',
                'detection_ms' => 400,
            ]);
        }

        $this->assertSame(1, $this->derivedCount(CompartmentDoorOpenDetected::class, $transactionId));
    }

    /**
     * The acknowledgement and the detection outcome come from two independent
     * queued reactors, so a fast door can be projected before the pulse
     * acknowledgement that preceded it.
     */
    public function test_a_late_acknowledgement_does_not_overwrite_a_physical_outcome(): void
    {
        $transactionId = 'txn-out-of-order';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'opened',
            'detection_ms' => 501,
        ]);

        event(new CompartmentOpenAcknowledged(
            lockerBankUuid: $this->lockerBank->id,
            compartmentUuid: (string) $this->compartment->id,
            compartmentNumber: 7,
            transactionId: $transactionId,
            timestamp: now()->toIso8601String(),
        ));

        $request = CompartmentOpenRequest::query()->find($transactionId);
        $this->assertSame(CompartmentOpenRequestStatus::Opened, $request?->status);
        $this->assertSame(501, $request?->open_detection_ms);
        $this->assertNotNull($request?->acknowledged_at, 'the pulse timestamp is still recorded');
    }

    public function test_a_late_acknowledgement_does_not_overwrite_a_jam(): void
    {
        $transactionId = 'txn-out-of-order-jam';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_failed', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'door_jammed',
            'error_code' => 'DOOR_JAMMED',
        ]);

        event(new CompartmentOpenAcknowledged(
            lockerBankUuid: $this->lockerBank->id,
            compartmentUuid: (string) $this->compartment->id,
            compartmentNumber: 7,
            transactionId: $transactionId,
            timestamp: now()->toIso8601String(),
        ));

        $request = CompartmentOpenRequest::query()->find($transactionId);
        $this->assertSame(CompartmentOpenRequestStatus::DoorJammed, $request?->status);
        $this->assertSame('DOOR_JAMMED', $request?->error_code);
    }

    public function test_detection_event_without_a_known_command_is_ignored(): void
    {
        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => 'txn-unknown',
            'outcome' => 'opened',
        ]);

        $this->assertSame(0, $this->derivedCount(CompartmentDoorOpenDetected::class, 'txn-unknown'));
    }

    public function test_uncommanded_open_is_recorded_and_alerts_operators(): void
    {
        Notification::fake();

        [$admin, $manager] = $this->givenOperatorsHoldingCompartmentOpen();

        $this->receiveDeviceEvent('compartment_uncommanded_open', [
            'compartment_number' => $this->compartment->number,
            'milliseconds_since_last_relay_fire' => 3_600_000,
        ]);

        $recorded = EloquentStoredEvent::query()
            ->where('event_class', CompartmentUncommandedOpenDetected::class)
            ->count();
        $this->assertSame(1, $recorded);

        // Managers and Admins both hold compartment.open, so both are alerted.
        Notification::assertSentTo($manager, CompartmentOpenDeviationNotification::class);
        Notification::assertSentTo($admin, CompartmentOpenDeviationNotification::class);
    }

    public function test_a_plain_user_is_not_alerted_about_uncommanded_opens(): void
    {
        Notification::fake();

        $this->givenOperatorsHoldingCompartmentOpen();
        $plainUser = User::factory()->create();

        $this->receiveDeviceEvent('compartment_uncommanded_open', [
            'compartment_number' => $this->compartment->number,
        ]);

        Notification::assertNotSentTo($plainUser, CompartmentOpenDeviationNotification::class);
    }

    public function test_uncommanded_open_for_an_unknown_compartment_is_ignored(): void
    {
        Notification::fake();

        $this->givenOperatorsHoldingCompartmentOpen();

        $this->receiveDeviceEvent('compartment_uncommanded_open', [
            'compartment_number' => 999,
        ]);

        $this->assertSame(
            0,
            EloquentStoredEvent::query()
                ->where('event_class', CompartmentUncommandedOpenDetected::class)
                ->count()
        );
        Notification::assertNothingSent();
    }

    /**
     * A jammed compartment reports door_state `closed` exactly like a healthy
     * one, so the admin list needs the fault surfaced separately.
     */
    public function test_a_jammed_compartment_is_flagged_on_the_compartment_list(): void
    {
        $transactionId = 'txn-flagged';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_failed', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'door_jammed',
            'error_code' => 'DOOR_JAMMED',
        ]);

        $compartment = $this->compartment->fresh();
        $this->assertSame(
            CompartmentOpenRequestStatus::DoorJammed,
            $compartment?->latestOpenRequest?->status,
            'the compartment list reads the fault from the latest open request'
        );
    }

    public function test_a_healthy_compartment_is_not_flagged(): void
    {
        $transactionId = 'txn-healthy';
        $this->givenAnOpenCommandWasSent($transactionId);

        $this->receiveDeviceEvent('compartment_open_detected', [
            'compartment_number' => 7,
            'transaction_id' => $transactionId,
            'outcome' => 'opened',
            'detection_ms' => 300,
        ]);

        $this->assertSame(
            CompartmentOpenRequestStatus::Opened,
            $this->compartment->fresh()?->latestOpenRequest?->status
        );
    }

    public function test_unrelated_device_events_are_left_alone(): void
    {
        $this->receiveDeviceEvent('qr_scanned', ['code' => 'abc']);

        $this->assertSame(0, EloquentStoredEvent::query()
            ->whereIn('event_class', [
                CompartmentDoorOpenDetected::class,
                CompartmentOpenNotDetected::class,
                CompartmentUncommandedOpenDetected::class,
            ])
            ->count());
    }

    private function givenAnOpenCommandWasSent(string $transactionId): void
    {
        event(new CompartmentOpeningRequested(
            lockerBankUuid: $this->lockerBank->id,
            compartmentUuid: (string) $this->compartment->id,
            compartmentNumber: 7,
            commandId: $transactionId,
        ));

        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $transactionId],
            [
                'compartment_id' => (string) $this->compartment->id,
                'status' => CompartmentOpenRequestStatus::Sent,
                // latestOpenRequest orders by requested_at, as the projector sets it.
                'requested_at' => now(),
                'sent_at' => now(),
            ]
        );
    }

    /**
     * Managers and Admins both hold `compartment.open`, the permission whose
     * definition covers receiving operational status updates.
     *
     * @return array{0: User, 1: User} admin, manager
     */
    private function givenOperatorsHoldingCompartmentOpen(): array
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, $admin->id, now())
            ->persist();

        return [$admin->fresh(), $manager->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function receiveDeviceEvent(string $event, array $data): void
    {
        event(new DeviceEventReceived(
            lockerBankUuid: $this->lockerBank->id,
            event: $event,
            eventId: null,
            timestamp: now()->toIso8601String(),
            data: $data,
        ));
    }

    private function derivedCount(string $eventClass, string $transactionId): int
    {
        return EloquentStoredEvent::query()
            ->where('event_class', $eventClass)
            ->where('event_properties->transactionId', $transactionId)
            ->count();
    }
}
