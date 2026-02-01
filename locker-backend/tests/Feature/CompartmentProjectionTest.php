<?php

namespace Tests\Feature;

use App\Models\Compartment;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use Database\Factories\CompartmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompartmentProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compartment_opened_updates_read_model(): void
    {
        /** @var Compartment $compartment */
        $compartment = CompartmentFactory::new()->create([
            'number' => 1,
        ]);

        $tx = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $ts = now()->toIso8601String();

        event(new CompartmentOpened(
            lockerBankUuid: (string) $compartment->locker_bank_id,
            compartmentUuid: (string) $compartment->id,
            compartmentNumber: (int) $compartment->number,
            transactionId: $tx,
            timestamp: $ts,
        ));

        $this->assertDatabaseHas('compartments', [
            'id' => (string) $compartment->id,
            'last_open_transaction_id' => $tx,
        ]);
    }

    public function test_compartment_open_failed_updates_read_model(): void
    {
        /** @var Compartment $compartment */
        $compartment = CompartmentFactory::new()->create([
            'number' => 1,
        ]);

        $tx = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $ts = now()->toIso8601String();

        event(new CompartmentOpeningFailed(
            lockerBankUuid: (string) $compartment->locker_bank_id,
            compartmentUuid: (string) $compartment->id,
            compartmentNumber: (int) $compartment->number,
            transactionId: $tx,
            errorCode: 'SIM',
            message: 'fail',
            timestamp: $ts,
        ));

        $this->assertDatabaseHas('compartments', [
            'id' => (string) $compartment->id,
            'last_open_transaction_id' => $tx,
            'last_open_error_code' => 'SIM',
        ]);
    }
}
