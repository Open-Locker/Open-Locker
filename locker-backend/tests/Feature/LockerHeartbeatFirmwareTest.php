<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FirmwareUpdateFailureReason;
use App\Enums\FirmwareUpdateStatus;
use App\Models\LockerBank;
use App\Mqtt\Handlers\CommandResponseHandler;
use App\Mqtt\Handlers\LockerHeartbeatHandler;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\Services\LockerService;
use App\StorableEvents\FirmwareUpdateCompleted;
use App\StorableEvents\FirmwareUpdateFailed;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * What the heartbeat says about the firmware, and how it ends an update
 * (ADR-0076, ADR-0077). Heartbeats are not event-sourced; the read model is
 * written directly, and only the end of an update becomes an event.
 */
class LockerHeartbeatFirmwareTest extends TestCase
{
    use RefreshDatabase;

    private int $messages = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->zeroOrMoreTimes();
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function heartbeat(LockerBank $bank, array $fields = []): void
    {
        app(LockerHeartbeatHandler::class)->handleMessage(
            "locker/{$bank->id}/state/heartbeat",
            (string) json_encode([
                'message_id' => 'hb-'.(++$this->messages).'-'.fake()->uuid(),
                'timestamp' => now()->toIso8601String(),
                'uptime_seconds' => 60,
                ...$fields,
            ]),
        );
    }

    /** A bank with an update to 0.2.0 in the given state. */
    private function bankUpdating(FirmwareUpdateStatus $status = FirmwareUpdateStatus::Downloading): LockerBank
    {
        $bank = LockerBankFactory::new()->create(['provisioned_at' => now(), 'firmware_update_capable' => true]);
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);
        app(LockerService::class)->requestFirmwareUpdate($bank, $release);
        $bank->refresh()->forceFill(['firmware_update_status' => $status])->save();

        return $bank->refresh();
    }

    private function storedCount(string $eventClass): int
    {
        return EloquentStoredEvent::query()->where('event_class', $eventClass)->count();
    }

    public function test_the_reported_firmware_is_stored_with_the_capability(): void
    {
        $bank = LockerBankFactory::new()->create();

        $this->heartbeat($bank, [
            'firmware_version' => '0.2.0',
            'trial' => true,
            'running_slot' => 'ota_1',
            'last_rollback_version' => '0.1.9',
            'capabilities' => ['firmware_update'],
        ]);

        $bank->refresh();
        $this->assertSame('0.2.0', $bank->firmware_version);
        $this->assertTrue($bank->firmware_trial);
        $this->assertSame('ota_1', $bank->firmware_running_slot);
        $this->assertSame('0.1.9', $bank->firmware_last_rollback_version);
        $this->assertTrue($bank->firmware_update_capable);
        $this->assertNotNull($bank->firmware_reported_at);
    }

    public function test_a_heartbeat_without_firmware_fields_leaves_what_is_stored_alone(): void
    {
        $bank = LockerBankFactory::new()->create([
            'firmware_version' => '0.2.0',
            'firmware_trial' => false,
            'firmware_running_slot' => 'ota_0',
            'firmware_update_capable' => true,
        ]);

        // The Pi client, and firmware from before updates, send none of them.
        $this->heartbeat($bank);

        $bank->refresh();
        $this->assertSame('0.2.0', $bank->firmware_version);
        $this->assertFalse($bank->firmware_trial);
        $this->assertSame('ota_0', $bank->firmware_running_slot);
        $this->assertTrue($bank->firmware_update_capable);
    }

    public function test_a_firmware_report_without_a_rollback_clears_the_remembered_one(): void
    {
        $bank = LockerBankFactory::new()->create(['firmware_last_rollback_version' => '0.1.9']);

        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => false]);

        $this->assertNull($bank->refresh()->firmware_last_rollback_version);
    }

    public function test_capabilities_without_firmware_update_clear_the_capability(): void
    {
        $bank = LockerBankFactory::new()->create(['firmware_update_capable' => true]);

        $this->heartbeat($bank, ['capabilities' => []]);
        $this->assertFalse($bank->refresh()->firmware_update_capable);

        $bank->forceFill(['firmware_update_capable' => true])->save();
        $this->heartbeat($bank, ['capabilities' => ['something_else']]);
        $this->assertFalse($bank->refresh()->firmware_update_capable);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedFirmwareReports(): array
    {
        return [
            'trial missing with the version' => [['firmware_version' => '0.2.0']],
            'trial not a boolean' => [['firmware_version' => '0.2.0', 'trial' => 'maybe']],
            'trial 0 instead of false' => [['firmware_version' => '0.2.0', 'trial' => 0]],
            'trial "1" instead of true' => [['firmware_version' => '0.2.0', 'trial' => '1']],
            'version too long' => [['firmware_version' => str_repeat('1', 32), 'trial' => false]],
            'unknown slot' => [['firmware_version' => '0.2.0', 'trial' => false, 'running_slot' => 'ota_2']],
            'capabilities a string' => [['capabilities' => 'firmware_update']],
            'capabilities an object' => [['capabilities' => ['feature' => 'firmware_update']]],
            'capabilities with a non-string' => [['capabilities' => ['firmware_update', 7]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('malformedFirmwareReports')]
    public function test_a_malformed_firmware_report_is_ignored_without_taking_the_locker_offline(array $fields): void
    {
        // Firmware is telemetry: a bad report must not make the bank miss heartbeats and look offline.
        $bank = $this->bankUpdating();
        $bank->forceFill(['last_heartbeat_at' => now()->subHour(), 'connection_status' => 'offline', 'firmware_version' => '0.1.0'])->save();

        $this->heartbeat($bank, [...$fields, 'modbus_connected' => false]);

        $bank->refresh();
        $this->assertTrue($bank->last_heartbeat_at->greaterThan(now()->subMinute()));
        $this->assertSame('online', $bank->connection_status);
        $this->assertFalse($bank->modbus_connected);
        // The whole firmware part is ignored: nothing stored, nothing completed or rolled back.
        $this->assertSame('0.1.0', $bank->firmware_version);
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->firmware_update_status);
        $this->assertTrue($bank->firmware_update_capable);
        $this->assertSame(0, $this->storedCount(FirmwareUpdateCompleted::class));
        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
    }

    public function test_a_malformed_report_that_names_the_target_does_not_complete_or_roll_back(): void
    {
        $bank = $this->bankUpdating();

        // `trial` missing, and a rollback of the target next to a malformed capability list.
        $this->heartbeat($bank, ['firmware_version' => '0.2.0']);
        $this->heartbeat($bank, ['firmware_version' => '0.1.0', 'trial' => false, 'last_rollback_version' => '0.2.0', 'capabilities' => 'x']);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateCompleted::class));
        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
    }

    public function test_the_warning_for_a_malformed_report_does_not_carry_the_payload(): void
    {
        $bank = $this->bankUpdating();
        Log::spy();

        $this->heartbeat($bank, ['firmware_version' => 'secret-looking-value', 'trial' => 'nope', 'capabilities' => 'x']);

        $lines = [];
        Log::shouldHaveReceived('warning')
            ->withArgs(function (mixed $message, mixed $context = []) use (&$lines): bool {
                $lines[] = $message.' '.json_encode($context);

                return true;
            })
            ->atLeast()->once();

        $ignored = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'Firmware report')));
        $this->assertNotEmpty($ignored);
        $this->assertStringContainsString('trial', $ignored[0]);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('secret-looking-value', $line);
            $this->assertStringNotContainsString('nope', $line);
        }
    }

    public function test_a_stale_rollback_does_not_fail_a_retry_before_the_device_refuses_it(): void
    {
        // 0.2.0 was rolled back before; the unit still reports it, and the operator tries 0.2.0 again.
        $bank = $this->bankUpdating(FirmwareUpdateStatus::Requested);
        $bank->forceFill(['firmware_last_rollback_version' => '0.2.0'])->save();

        $this->heartbeat($bank, ['firmware_version' => '0.1.0', 'trial' => false, 'last_rollback_version' => '0.2.0']);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
        $this->assertSame(FirmwareUpdateStatus::Requested, $bank->refresh()->firmware_update_status);

        // The device refuses the rolled-back version, and that is what fails the update.
        app(CommandResponseHandler::class)->handleMessage("locker/{$bank->id}/response", (string) json_encode([
            'message_id' => fake()->uuid(),
            'transaction_id' => $bank->firmware_update_transaction_id,
            'action' => 'update_firmware',
            'result' => 'error',
            'error_code' => 'UPDATE_VERSION_REJECTED',
            'message' => 'The image version is not accepted.',
            'timestamp' => now()->toIso8601String(),
        ]));

        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Failed, $bank->firmware_update_status);
        $this->assertSame('UPDATE_VERSION_REJECTED', $bank->firmware_update_error_code);
        $this->assertNull($bank->firmware_update_failure_reason);
    }

    public function test_the_target_version_outside_its_trial_completes_the_update_once(): void
    {
        $bank = $this->bankUpdating();

        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => false, 'capabilities' => ['firmware_update']]);
        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => false, 'capabilities' => ['firmware_update']]);

        $this->assertSame(1, $this->storedCount(FirmwareUpdateCompleted::class));
        $stored = EloquentStoredEvent::query()->where('event_class', FirmwareUpdateCompleted::class)->first();
        $this->assertSame($bank->firmware_update_transaction_id, $stored->event_properties['transactionId']);
        $this->assertSame('0.2.0', $stored->event_properties['version']);
        $this->assertSame(FirmwareUpdateStatus::Completed, $bank->refresh()->firmware_update_status);
    }

    public function test_a_response_that_never_arrived_does_not_stop_the_update_from_completing(): void
    {
        $bank = $this->bankUpdating(FirmwareUpdateStatus::Requested);

        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => false]);

        $this->assertSame(FirmwareUpdateStatus::Completed, $bank->refresh()->firmware_update_status);
    }

    public function test_the_target_version_on_trial_completes_nothing(): void
    {
        $bank = $this->bankUpdating();

        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => true]);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateCompleted::class));
        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->firmware_update_status);
        $this->assertTrue($bank->firmware_trial);
    }

    public function test_another_version_completes_nothing(): void
    {
        $bank = $this->bankUpdating();

        $this->heartbeat($bank, ['firmware_version' => '0.1.0', 'trial' => false]);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateCompleted::class));
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->refresh()->firmware_update_status);
    }

    public function test_the_target_version_with_no_update_in_flight_completes_nothing(): void
    {
        $bank = $this->bankUpdating(FirmwareUpdateStatus::Failed);

        $this->heartbeat($bank, ['firmware_version' => '0.2.0', 'trial' => false]);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateCompleted::class));
        $this->assertSame(FirmwareUpdateStatus::Failed, $bank->refresh()->firmware_update_status);
    }

    public function test_a_rollback_of_the_target_fails_the_update_once_with_the_rollback_reason(): void
    {
        $bank = $this->bankUpdating();

        $fields = ['firmware_version' => '0.1.0', 'trial' => false, 'last_rollback_version' => '0.2.0'];
        $this->heartbeat($bank, $fields);
        $this->heartbeat($bank, $fields);

        $this->assertSame(1, $this->storedCount(FirmwareUpdateFailed::class));
        $stored = EloquentStoredEvent::query()->where('event_class', FirmwareUpdateFailed::class)->first();
        $this->assertSame('rollback', $stored->event_properties['reason']);
        $this->assertNull($stored->event_properties['errorCode']);
        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Failed, $bank->firmware_update_status);
        $this->assertSame(FirmwareUpdateFailureReason::Rollback, $bank->firmware_update_failure_reason);
        $this->assertNull($bank->firmware_update_error_code);
    }

    public function test_a_rollback_of_another_version_does_not_fail_this_update(): void
    {
        $bank = $this->bankUpdating();

        $this->heartbeat($bank, ['firmware_version' => '0.1.0', 'trial' => false, 'last_rollback_version' => '0.1.5']);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->refresh()->firmware_update_status);
    }
}
