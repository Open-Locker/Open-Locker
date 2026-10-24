<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FirmwareUpdateStatus;
use App\Models\LockerBank;
use App\Mqtt\Handlers\CommandResponseHandler;
use App\Mqtt\Handlers\DeviceEventHandler;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\Services\LockerService;
use App\StorableEvents\FirmwareUpdateAccepted;
use App\StorableEvents\FirmwareUpdateFailed;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * How an update_firmware command ends: one response (accepted or refused), or,
 * after an accepted command, a firmware_update_failed device event (ADR-0076).
 */
class FirmwareUpdateOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private LockerBank $bank;

    private string $transactionId;

    protected function setUp(): void
    {
        parent::setUp();

        // As in production: a request is refused unless its download link is an https URL the firmware accepts.
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
        Cache::flush();
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->zeroOrMoreTimes();
        });

        $this->bank = LockerBankFactory::new()->create(['provisioned_at' => now(), 'firmware_update_capable' => true]);
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);
        $this->transactionId = app(LockerService::class)->requestFirmwareUpdate($this->bank, $release);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function respond(array $overrides = [], ?string $messageId = null, ?LockerBank $from = null, ?string $transactionId = null): void
    {
        $from ??= $this->bank;

        app(CommandResponseHandler::class)->handleMessage("locker/{$from->id}/response", (string) json_encode([
            'message_id' => $messageId ?? fake()->uuid(),
            'transaction_id' => $transactionId ?? $this->transactionId,
            'action' => 'update_firmware',
            'result' => 'success',
            'timestamp' => now()->toIso8601String(),
            'message' => 'Update accepted; download started.',
            ...$overrides,
        ]));
    }

    private function failureEvent(string $errorCode = 'UPDATE_DOWNLOAD_FAILED', ?string $transactionId = null): void
    {
        app(DeviceEventHandler::class)->handleMessage("locker/{$this->bank->id}/event", (string) json_encode([
            'message_id' => fake()->uuid(),
            'event' => 'firmware_update_failed',
            'timestamp' => now()->toIso8601String(),
            'data' => ['transaction_id' => $transactionId ?? $this->transactionId, 'error_code' => $errorCode],
        ]));
    }

    private function storedCount(string $eventClass): int
    {
        return EloquentStoredEvent::query()->where('event_class', $eventClass)->count();
    }

    public function test_a_success_response_records_the_acceptance_once(): void
    {
        $messageId = fake()->uuid();
        $this->respond(messageId: $messageId);
        $this->respond(messageId: fake()->uuid()); // the broker delivers it again

        $this->assertSame(1, $this->storedCount(FirmwareUpdateAccepted::class));
        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
        $this->bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Downloading, $this->bank->firmware_update_status);
        $this->assertSame($this->transactionId, $this->bank->firmware_update_transaction_id);
    }

    public function test_an_error_response_records_the_refusal_with_its_code(): void
    {
        $this->respond(['result' => 'error', 'error_code' => 'UPDATE_NOT_READY', 'message' => 'The device is not ready to update.']);
        $this->respond(['result' => 'error', 'error_code' => 'UPDATE_NOT_READY', 'message' => 'The device is not ready to update.']);

        $this->assertSame(1, $this->storedCount(FirmwareUpdateFailed::class));
        $this->assertSame(0, $this->storedCount(FirmwareUpdateAccepted::class));
        $stored = EloquentStoredEvent::query()->where('event_class', FirmwareUpdateFailed::class)->first();
        $this->assertSame('UPDATE_NOT_READY', $stored->event_properties['errorCode']);
        $this->assertSame($this->transactionId, $stored->event_properties['transactionId']);

        $this->bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Failed, $this->bank->firmware_update_status);
        $this->assertSame('UPDATE_NOT_READY', $this->bank->firmware_update_error_code);
    }

    public function test_a_response_from_another_bank_with_this_transaction_id_changes_nothing_and_blocks_nothing(): void
    {
        $other = LockerBankFactory::new()->create();

        $this->respond(from: $other);
        $this->respond(['result' => 'error', 'error_code' => 'UPDATE_BUSY'], from: $other);

        $this->assertSame(0, $this->storedCount(FirmwareUpdateAccepted::class));
        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
        $this->assertSame(FirmwareUpdateStatus::Requested, $this->bank->refresh()->firmware_update_status);

        // The bank that was asked still gets its own response through.
        $this->respond();

        $this->assertSame(1, $this->storedCount(FirmwareUpdateAccepted::class));
        $this->assertSame(FirmwareUpdateStatus::Downloading, $this->bank->refresh()->firmware_update_status);
    }

    public function test_a_response_for_a_transaction_nobody_requested_changes_nothing(): void
    {
        $this->respond(transactionId: '99999999-9999-9999-9999-999999999999');

        $this->assertSame(0, $this->storedCount(FirmwareUpdateAccepted::class));
        $this->assertSame(FirmwareUpdateStatus::Requested, $this->bank->refresh()->firmware_update_status);
    }

    public function test_a_failure_event_after_the_acceptance_records_the_failure_once(): void
    {
        $this->respond();
        $this->failureEvent('UPDATE_VERIFY_FAILED');
        $this->failureEvent('UPDATE_VERIFY_FAILED'); // redelivered under a new message id

        $this->assertSame(1, $this->storedCount(FirmwareUpdateFailed::class));
        $stored = EloquentStoredEvent::query()->where('event_class', FirmwareUpdateFailed::class)->first();
        $this->assertSame('UPDATE_VERIFY_FAILED', $stored->event_properties['errorCode']);

        $this->bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Failed, $this->bank->firmware_update_status);
        $this->assertSame('UPDATE_VERIFY_FAILED', $this->bank->firmware_update_error_code);
    }

    public function test_a_failure_event_for_a_transaction_nobody_asked_for_changes_nothing(): void
    {
        $this->respond();

        $this->failureEvent('UPDATE_DOWNLOAD_FAILED', '99999999-9999-9999-9999-999999999999');

        $this->assertSame(0, $this->storedCount(FirmwareUpdateFailed::class));
        $this->assertSame(FirmwareUpdateStatus::Downloading, $this->bank->refresh()->firmware_update_status);
    }

    public function test_a_late_acceptance_does_not_undo_a_failure(): void
    {
        $this->respond(['result' => 'error', 'error_code' => 'UPDATE_BUSY']);

        // Whatever arrives afterwards for a transaction that already ended is history, not state.
        event(new FirmwareUpdateAccepted(lockerBankUuid: (string) $this->bank->id, transactionId: $this->transactionId));

        $this->assertSame(FirmwareUpdateStatus::Failed, $this->bank->refresh()->firmware_update_status);
    }

    public function test_a_response_for_an_older_transaction_does_not_touch_the_current_update(): void
    {
        $this->respond(['result' => 'error', 'error_code' => 'UPDATE_BUSY']);
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.3.0']);
        $second = app(LockerService::class)->requestFirmwareUpdate($this->bank->refresh(), $release);

        event(new FirmwareUpdateFailed(
            lockerBankUuid: (string) $this->bank->id,
            transactionId: $this->transactionId,
            errorCode: 'UPDATE_BUSY',
        ));

        $this->bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Requested, $this->bank->firmware_update_status);
        $this->assertSame($second, $this->bank->firmware_update_transaction_id);
    }
}
