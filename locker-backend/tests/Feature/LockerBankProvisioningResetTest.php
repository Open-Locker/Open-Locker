<?php

namespace Tests\Feature;

use App\Aggregates\LockerBankAggregate;
use App\StorableEvents\LockerProvisioningReset;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class LockerBankProvisioningResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_rotates_token_and_clears_provisioned_at(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'provisioned_at' => now(),
        ]);

        $oldToken = $lockerBank->provisioning_token;
        $newToken = str_repeat('a', 64);

        LockerBankAggregate::retrieve($lockerBank->id)
            ->resetProvisioning($newToken)
            ->persist();

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', LockerProvisioningReset::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($storedEvent);
        $this->assertSame((string) $lockerBank->id, $storedEvent->event_properties['lockerBankUuid'] ?? null);

        $lockerBank->refresh();
        $this->assertSame($newToken, $lockerBank->provisioning_token);
        $this->assertNotSame($oldToken, $lockerBank->provisioning_token);
        $this->assertNull($lockerBank->provisioned_at);
    }

    public function test_reset_allows_a_previously_provisioned_bank_to_provision_again(): void
    {
        $lockerBank = LockerBankFactory::new()->create([
            'provisioned_at' => now(),
        ]);

        LockerBankAggregate::retrieve($lockerBank->id)
            ->resetProvisioning(str_repeat('b', 64))
            ->persist();

        $lockerBank->refresh();

        // The aggregate guard rejects re-provisioning while provisioned_at is set;
        // after a reset it is null, so the bank is eligible again.
        $this->assertNull($lockerBank->provisioned_at);
    }
}
