<?php

namespace Tests\Feature;

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
}
