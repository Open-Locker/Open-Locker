<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FirmwareUpdateStatus;
use App\Exceptions\FirmwareUpdateRefused;
use App\Models\LockerBank;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\Services\LockerService;
use App\StorableEvents\FirmwareUpdateRequested;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class LockerServiceFirmwareUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As in production: a request is refused unless its download link is an https URL the firmware accepts.
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
    }

    /** A bank that can be updated: provisioned, and its heartbeat lists `firmware_update`. */
    private function updatableBank(): LockerBank
    {
        return LockerBankFactory::new()->create([
            'provisioned_at' => now(),
            'firmware_update_capable' => true,
        ]);
    }

    private function requested(): ?EloquentStoredEvent
    {
        return EloquentStoredEvent::query()->where('event_class', FirmwareUpdateRequested::class)->first();
    }

    public function test_a_request_records_an_event_with_the_release_not_the_link_and_projects_the_status(): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });
        $bank = $this->updatableBank();
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);
        $actor = UserFactory::new()->create();

        $transactionId = app(LockerService::class)->requestFirmwareUpdate($bank, $release, true, $actor->id);

        $stored = $this->requested();
        $this->assertNotNull($stored);
        $properties = $stored->event_properties;
        $this->assertSame((string) $bank->id, $properties['lockerBankUuid']);
        $this->assertSame($transactionId, $properties['commandId']);
        $this->assertSame((string) $release->id, $properties['releaseId']);
        $this->assertSame('0.2.0', $properties['version']);
        $this->assertTrue($properties['allowDowngrade']);
        $this->assertSame($actor->id, $properties['actorUserId']);
        // The event is permanent history: the download link, a credential, is made at publish time.
        $this->assertStringNotContainsString('signature', (string) json_encode($properties));
        $this->assertStringNotContainsString('http', (string) json_encode($properties));

        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Requested, $bank->firmware_update_status);
        $this->assertSame($transactionId, $bank->firmware_update_transaction_id);
        $this->assertSame((string) $release->id, $bank->firmware_update_release_id);
        $this->assertSame('0.2.0', $bank->firmware_update_target_version);
        $this->assertNull($bank->firmware_update_error_code);
        $this->assertNotNull($bank->firmware_update_requested_at);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'not provisioned' => [['provisioned_at' => null], 'not_provisioned'],
            'heartbeat did not advertise firmware_update' => [['firmware_update_capable' => false], 'not_capable'],
            'update requested' => [['firmware_update_status' => 'requested'], 'update_in_flight'],
            'update downloading' => [['firmware_update_status' => 'downloading'], 'update_in_flight'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[DataProvider('refusals')]
    public function test_a_bank_that_cannot_take_an_update_is_refused_and_nothing_is_recorded(array $state, string $reason): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->never();
        });
        $bank = $this->updatableBank();
        $bank->forceFill($state)->save();
        $release = FirmwareReleaseFactory::new()->create();

        try {
            app(LockerService::class)->requestFirmwareUpdate($bank, $release);
            $this->fail('The request was accepted.');
        } catch (FirmwareUpdateRefused $refused) {
            $this->assertSame($reason, $refused->reason->value);
        }

        // A refusal is not history: no event, no state, and it can be tried again.
        $this->assertNull($this->requested());
    }

    public function test_a_release_marked_invalid_is_refused(): void
    {
        $bank = $this->updatableBank();
        $release = FirmwareReleaseFactory::new()->create(['invalid' => true]);

        try {
            app(LockerService::class)->requestFirmwareUpdate($bank, $release);
            $this->fail('The request was accepted.');
        } catch (FirmwareUpdateRefused $refused) {
            $this->assertSame('release_invalid', $refused->reason->value);
        }

        $this->assertNull($this->requested());
    }

    public function test_a_download_link_the_firmware_would_refuse_is_refused_before_anything_is_recorded(): void
    {
        $bank = $this->updatableBank();
        $release = FirmwareReleaseFactory::new()->create();

        // A public URL without https, and one with a host so long the link passes 256 characters.
        foreach (['http://open-locker.cloud', 'https://'.str_repeat('a', 70).'.open-locker.example.com'] as $root) {
            URL::forceRootUrl($root);
            URL::forceScheme(str_starts_with($root, 'https') ? 'https' : 'http');

            try {
                app(LockerService::class)->requestFirmwareUpdate($bank, $release);
                $this->fail("The request was accepted for {$root}.");
            } catch (FirmwareUpdateRefused $refused) {
                $this->assertSame('download_url_unusable', $refused->reason->value);
            }
        }

        $this->assertNull($this->requested());
        $this->assertNull($bank->refresh()->firmware_update_status);
    }

    public function test_a_failed_update_does_not_block_the_next_one(): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });
        $bank = $this->updatableBank();
        $bank->forceFill(['firmware_update_status' => 'failed', 'firmware_update_error_code' => 'UPDATE_BUSY'])->save();
        $release = FirmwareReleaseFactory::new()->create();

        app(LockerService::class)->requestFirmwareUpdate($bank, $release);

        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Requested, $bank->firmware_update_status);
        $this->assertNull($bank->firmware_update_error_code);
    }

    public function test_a_second_request_is_refused_even_with_a_stale_copy_of_the_bank(): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });
        $bank = $this->updatableBank();
        $stale = LockerBank::query()->findOrFail($bank->id); // read before the first request
        $release = FirmwareReleaseFactory::new()->create();
        $service = app(LockerService::class);

        $service->requestFirmwareUpdate($bank, $release);

        // Two requests at once: the second holds a copy that still says idle. The service reads the bank again, under a lock.
        $this->assertNull($stale->firmware_update_status);
        $this->expectException(FirmwareUpdateRefused::class);
        $service->requestFirmwareUpdate($stale, $release);
    }
}
