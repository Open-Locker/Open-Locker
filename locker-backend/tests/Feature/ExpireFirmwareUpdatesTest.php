<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ExpireFirmwareUpdates;
use App\Enums\FirmwareUpdateFailureReason;
use App\Enums\FirmwareUpdateStatus;
use App\Models\LockerBank;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\Services\LockerService;
use App\StorableEvents\FirmwareUpdateFailed;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * An update with no outcome is failed by the backend after 30 minutes
 * (ADR-0077): the device cannot report a lost event or a restart.
 */
class ExpireFirmwareUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->zeroOrMoreTimes();
        });
    }

    private function bankUpdating(FirmwareUpdateStatus $status = FirmwareUpdateStatus::Downloading): LockerBank
    {
        $bank = LockerBankFactory::new()->create(['provisioned_at' => now(), 'firmware_update_capable' => true]);
        $release = FirmwareReleaseFactory::new()->create();
        app(LockerService::class)->requestFirmwareUpdate($bank, $release);
        $bank->refresh()->forceFill(['firmware_update_status' => $status])->save();

        return $bank->refresh();
    }

    private function failures(): int
    {
        return EloquentStoredEvent::query()->where('event_class', FirmwareUpdateFailed::class)->count();
    }

    public function test_an_update_still_in_flight_after_thirty_minutes_fails_with_the_timeout_reason(): void
    {
        $downloading = $this->bankUpdating(FirmwareUpdateStatus::Downloading);
        $requested = $this->bankUpdating(FirmwareUpdateStatus::Requested);

        $this->travel(ExpireFirmwareUpdates::TIMEOUT_MINUTES + 1)->minutes();
        $this->artisan('locker:expire-firmware-updates')->assertSuccessful();

        $this->assertSame(2, $this->failures());
        $stored = EloquentStoredEvent::query()->where('event_class', FirmwareUpdateFailed::class)->first();
        $this->assertSame('timeout', $stored->event_properties['reason']);
        $this->assertNull($stored->event_properties['errorCode']);
        foreach ([$downloading, $requested] as $bank) {
            $bank->refresh();
            $this->assertSame(FirmwareUpdateStatus::Failed, $bank->firmware_update_status);
            $this->assertSame(FirmwareUpdateFailureReason::Timeout, $bank->firmware_update_failure_reason);
            $this->assertNull($bank->firmware_update_error_code);
        }
    }

    public function test_each_update_times_out_exactly_once(): void
    {
        $this->bankUpdating();

        $this->travel(ExpireFirmwareUpdates::TIMEOUT_MINUTES + 1)->minutes();
        $this->artisan('locker:expire-firmware-updates')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->artisan('locker:expire-firmware-updates')->assertSuccessful();

        $this->assertSame(1, $this->failures());
    }

    public function test_an_update_is_left_alone_until_the_timeout_has_passed(): void
    {
        $bank = $this->bankUpdating();

        $this->travel(ExpireFirmwareUpdates::TIMEOUT_MINUTES - 1)->minutes();
        $this->artisan('locker:expire-firmware-updates')->assertSuccessful();

        $this->assertSame(0, $this->failures());
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->refresh()->firmware_update_status);
    }

    public function test_a_finished_update_is_left_alone(): void
    {
        $completed = $this->bankUpdating(FirmwareUpdateStatus::Completed);
        $failed = $this->bankUpdating(FirmwareUpdateStatus::Failed);
        $failed->forceFill(['firmware_update_error_code' => 'UPDATE_BUSY'])->save();
        $idle = LockerBankFactory::new()->create();

        $this->travel(ExpireFirmwareUpdates::TIMEOUT_MINUTES * 3)->minutes();
        $this->artisan('locker:expire-firmware-updates')->assertSuccessful();

        $this->assertSame(0, $this->failures());
        $this->assertSame(FirmwareUpdateStatus::Completed, $completed->refresh()->firmware_update_status);
        $this->assertSame('UPDATE_BUSY', $failed->refresh()->firmware_update_error_code);
        $this->assertNull($idle->refresh()->firmware_update_status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $bank = $this->bankUpdating();

        $this->travel(ExpireFirmwareUpdates::TIMEOUT_MINUTES + 1)->minutes();
        $this->artisan('locker:expire-firmware-updates', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $this->failures());
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->refresh()->firmware_update_status);
    }

    public function test_the_command_is_scheduled_every_minute_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'locker:expire-firmware-updates'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
