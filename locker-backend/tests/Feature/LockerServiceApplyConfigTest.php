<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LockerAdapterType;
use App\Enums\LockerFeedbackType;
use App\Models\LockerBank;
use App\Mqtt\Publishers\ApplyConfigCommandPublisher;
use App\Services\LockerService;
use App\StorableEvents\LockerConfigApplyRequested;
use Database\Factories\CompartmentFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class LockerServiceApplyConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_compatible_hardware_profile_defaults_are_applied(): void
    {
        $lockerBank = LockerBank::query()->create([
            'name' => 'Default profile bank',
            'location_description' => null,
        ])->refresh();

        $this->assertSame(LockerAdapterType::WaveshareModbus, $lockerBank->adapter_type);
        $this->assertSame(8, $lockerBank->channel_count);
        $this->assertSame(LockerFeedbackType::DoorClosing, $lockerBank->feedback_type);
    }

    public function test_apply_config_succeeds_when_only_other_locker_banks_have_incomplete_compartments(): void
    {
        $completeBank = LockerBankFactory::new()->create();
        CompartmentFactory::new()->create([
            'locker_bank_id' => $completeBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 0,
        ]);

        $otherBank = LockerBankFactory::new()->create();
        CompartmentFactory::new()->create([
            'locker_bank_id' => $otherBank->id,
            'number' => 1,
            'slave_id' => null,
            'address' => null,
        ]);

        $this->mock(ApplyConfigCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });

        app(LockerService::class)->applyConfig($completeBank);

        $stored = EloquentStoredEvent::query()
            ->where('event_class', LockerConfigApplyRequested::class)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('waveshare_modbus', $stored->event_properties['adapterType'] ?? null);
        $this->assertSame(8, $stored->event_properties['channelCount'] ?? null);
        $this->assertSame('door_closing', $stored->event_properties['feedbackType'] ?? null);
        $this->assertNotNull($completeBank->refresh()->last_config_sent_at);
    }

    public function test_apply_config_fails_when_own_compartments_are_incomplete(): void
    {
        $lockerBank = LockerBankFactory::new()->create();
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is incomplete: every compartment needs slave_id and address.');

        app(LockerService::class)->applyConfig($lockerBank);
    }

    public function test_config_hash_uses_canonical_hardware_profile_and_sorted_compartments_but_excludes_heartbeat(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'adapter_type' => LockerAdapterType::Rs485LockBoard,
            'channel_count' => 12,
            'feedback_type' => LockerFeedbackType::DoorOpening,
            'heartbeat_interval_seconds' => 15,
        ]);
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 2,
            'slave_id' => 2,
            'address' => 11,
        ]);
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 1,
            'slave_id' => 2,
            'address' => 0,
        ]);

        $payload = $lockerBank->buildApplyConfigPayload();

        $this->assertSame('041f1edf0ee6921b6727d250a966da978beb0af11c6b6817dfd11a083a0e0c68', $payload['config_hash']);
        $this->assertSame('rs485_lock_board', $payload['adapter_type']);
        $this->assertSame(12, $payload['channel_count']);
        $this->assertSame('door_opening', $payload['feedback_type']);
        $this->assertSame([1, 2], array_column($payload['compartments'], 'compartment_number'));

        $lockerBank->update(['heartbeat_interval_seconds' => 30]);

        $this->assertSame($payload['config_hash'], $lockerBank->fresh()->currentConfigHash());
    }

    public function test_config_hash_changes_when_hardware_profile_changes(): void
    {
        $lockerBank = LockerBankFactory::new()->create();

        $originalHash = $lockerBank->currentConfigHash();
        $lockerBank->update(['feedback_type' => LockerFeedbackType::DoorOpening]);

        $this->assertNotSame($originalHash, $lockerBank->fresh()->currentConfigHash());
    }

    public function test_apply_config_accepts_highest_address_within_channel_count(): void
    {
        $lockerBank = LockerBankFactory::new()->create(['channel_count' => 8]);
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 7,
        ]);

        $this->mock(ApplyConfigCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });

        app(LockerService::class)->applyConfig($lockerBank);

        $this->assertNotNull($lockerBank->refresh()->last_config_sent_at);
    }

    public function test_apply_config_rejects_address_equal_to_channel_count(): void
    {
        $lockerBank = LockerBankFactory::new()->create(['channel_count' => 8]);
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 8,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is invalid: every compartment address must be less than channel_count (8).');

        app(LockerService::class)->applyConfig($lockerBank);
    }

    public function test_apply_config_rejects_unsupported_channel_count(): void
    {
        $lockerBank = LockerBankFactory::new()->create(['channel_count' => 10]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is invalid: channel_count must be one of 8, 12, 18, 24, 36, or 50.');

        app(LockerService::class)->applyConfig($lockerBank);
    }

    public function test_apply_config_rejects_rs485_board_address_outside_dip_range(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'adapter_type' => LockerAdapterType::Rs485LockBoard,
        ]);
        CompartmentFactory::new()->create([
            'locker_bank_id' => $lockerBank->id,
            'number' => 1,
            'slave_id' => 32,
            'address' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is invalid: RS485 locker board slave_id must be between 1 and 31.');

        app(LockerService::class)->applyConfig($lockerBank);
    }
}
