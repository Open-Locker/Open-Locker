<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBankClientConfig;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentMappingRelationManager;
use App\Filament\Resources\LockerBankResource\RelationManagers\CompartmentsRelationManager;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\User;
use App\Services\LockerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LockerBankClientConfigPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_settings_page_keeps_backend_fields_and_hides_sendable_config(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditLockerBank::class, ['record' => $lockerBank->getKey()])
            ->assertSuccessful()
            ->assertSee(__('Location description'))
            ->assertSee(__('Heartbeat timeout (seconds)'))
            ->assertDontSee(__('Heartbeat interval (seconds)'))
            ->assertDontSee(__('Hardware adapter'))
            ->assertDontSee(__('Send config to client'))
            ->assertDontSee(__('Slave ID'));
    }

    public function test_client_config_page_shows_sendable_fields_and_send_action(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();

        Livewire::actingAs($admin)
            ->test(EditLockerBankClientConfig::class, ['record' => $lockerBank->getKey()])
            ->assertSuccessful()
            ->assertSee(__('Heartbeat interval (seconds)'))
            ->assertSee(__('Hardware adapter'))
            ->assertSee(__('Send config to client'))
            ->assertSee(__('Heartbeat interval, hardware profile, and compartment mapping only take effect after you send them to the client.'))
            ->assertSee(__('Dirty (not confirmed by client yet)'))
            ->assertDontSee(__('Location description'))
            ->assertDontSee(__('Heartbeat timeout (seconds)'));
    }

    public function test_send_config_action_is_disabled_until_provisioned(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create(['provisioned_at' => null]);

        Livewire::actingAs($admin)
            ->test(EditLockerBankClientConfig::class, ['record' => $lockerBank->getKey()])
            ->assertActionDisabled('sendConfigToClient');
    }

    public function test_send_config_action_calls_apply_config(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create([
            'provisioned_at' => now(),
        ]);
        Compartment::factory()->for($lockerBank)->create([
            'number' => 1,
            'slave_id' => 1,
            'address' => 0,
        ]);

        $this->mock(LockerService::class, function ($mock) use ($lockerBank): void {
            $mock->shouldReceive('applyConfig')
                ->once()
                ->withArgs(fn (LockerBank $record): bool => $record->is($lockerBank));
        });

        Livewire::actingAs($admin)
            ->test(EditLockerBankClientConfig::class, ['record' => $lockerBank->getKey()])
            ->callAction('sendConfigToClient')
            ->assertNotified(__('Configuration sent'));
    }

    public function test_saving_dirty_client_config_reminds_to_send(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create([
            'heartbeat_interval_seconds' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(EditLockerBankClientConfig::class, ['record' => $lockerBank->getKey()])
            ->fillForm([
                'heartbeat_interval_seconds' => 20,
            ])
            ->call('save')
            ->assertNotified(__('Client config saved, but not sent yet'));

        $this->assertSame(20, $lockerBank->refresh()->heartbeat_interval_seconds);
    }

    public function test_mapping_relation_belongs_to_client_config_page(): void
    {
        $admin = $this->admin();
        $lockerBank = LockerBank::factory()->create();
        Compartment::factory()->for($lockerBank)->create([
            'number' => 3,
            'slave_id' => 7,
            'address' => 2,
        ]);

        $this->assertTrue(CompartmentMappingRelationManager::canViewForRecord(
            $lockerBank,
            EditLockerBankClientConfig::class,
        ));
        $this->assertFalse(CompartmentMappingRelationManager::canViewForRecord(
            $lockerBank,
            EditLockerBank::class,
        ));
        $this->assertTrue(CompartmentsRelationManager::canViewForRecord(
            $lockerBank,
            EditLockerBank::class,
        ));
        $this->assertFalse(CompartmentsRelationManager::canViewForRecord(
            $lockerBank,
            EditLockerBankClientConfig::class,
        ));

        Livewire::actingAs($admin)
            ->test(CompartmentMappingRelationManager::class, [
                'ownerRecord' => $lockerBank,
                'pageClass' => EditLockerBankClientConfig::class,
            ])
            ->assertSuccessful()
            ->assertSee('7')
            ->assertSee('2');
    }
}
