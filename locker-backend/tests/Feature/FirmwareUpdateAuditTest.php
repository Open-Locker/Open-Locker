<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\StorableEvents\FirmwareUpdateAccepted;
use App\StorableEvents\FirmwareUpdateFailed;
use App\StorableEvents\FirmwareUpdateRequested;
use App\Support\Audit\AuditEventPresenter;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class FirmwareUpdateAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
    }

    private function describe(string $eventClass): string
    {
        $stored = EloquentStoredEvent::query()->where('event_class', $eventClass)->latest('id')->firstOrFail();

        return app(AuditEventPresenter::class)->describe($stored);
    }

    public function test_the_three_firmware_events_read_as_sentences_in_the_audit_log(): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });
        $bank = LockerBankFactory::new()->create(['name' => 'North wall']);
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);
        $actor = UserFactory::new()->create(['first_name' => 'Ada', 'last_name' => 'Admin']);

        event(new FirmwareUpdateRequested(
            lockerBankUuid: (string) $bank->id,
            commandId: 'txn-1',
            releaseId: (string) $release->id,
            version: '0.2.0',
            allowDowngrade: false,
            actorUserId: $actor->id,
            requestedAtIso8601: now()->toIso8601String(),
        ));
        event(new FirmwareUpdateAccepted(lockerBankUuid: (string) $bank->id, transactionId: 'txn-1'));
        event(new FirmwareUpdateFailed(lockerBankUuid: (string) $bank->id, transactionId: 'txn-1', errorCode: 'UPDATE_VERIFY_FAILED'));

        $requested = $this->describe(FirmwareUpdateRequested::class);
        $this->assertStringContainsString('Ada Admin', $requested);
        $this->assertStringContainsString('0.2.0', $requested);
        $this->assertStringContainsString('North wall', $requested);
        $this->assertStringNotContainsString('http', $requested);
        $this->assertStringContainsString('North wall', $this->describe(FirmwareUpdateAccepted::class));
        $failed = $this->describe(FirmwareUpdateFailed::class);
        $this->assertStringContainsString('North wall', $failed);
        $this->assertStringContainsString('UPDATE_VERIFY_FAILED', $failed);
    }
}
