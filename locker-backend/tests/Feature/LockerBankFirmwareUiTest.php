<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\FirmwareUpdateFailureReason;
use App\Enums\FirmwareUpdateStatus;
use App\Enums\Role;
use App\Filament\Resources\LockerBankResource;
use App\Filament\Resources\LockerBankResource\Pages\EditLockerBank;
use App\Filament\Resources\LockerBankResource\Pages\ListLockerBanks;
use App\Models\LockerBank;
use App\Models\User;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The firmware column and the "Update firmware" action of the locker bank
 * admin (ADR-0077).
 */
class LockerBankFirmwareUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->zeroOrMoreTimes();
        });
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function capableBank(array $attributes = []): LockerBank
    {
        return LockerBankFactory::new()->create($attributes + [
            'provisioned_at' => now(),
            'firmware_update_capable' => true,
            'firmware_version' => '0.1.0',
        ]);
    }

    public function test_the_create_page_loads_without_a_record(): void
    {
        $this->actingAs($this->admin())->get(LockerBankResource::getUrl('create'))->assertOk();
    }

    public function test_the_list_shows_the_reported_version_and_the_update_status(): void
    {
        $bank = $this->capableBank(['firmware_update_status' => FirmwareUpdateStatus::Downloading]);

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->assertCanSeeTableRecords([$bank])
            ->assertTableColumnStateSet('firmware_version', '0.1.0', $bank)
            ->assertTableColumnStateSet('firmware_update_status', 'downloading', $bank);
    }

    public function test_the_edit_page_shows_the_firmware_details(): void
    {
        $bank = $this->capableBank([
            'firmware_running_slot' => 'ota_1',
            'firmware_trial' => true,
            'firmware_last_rollback_version' => '0.0.9',
            'firmware_update_status' => FirmwareUpdateStatus::Failed,
            'firmware_update_error_code' => 'UPDATE_DOWNLOAD_FAILED',
            'firmware_update_failure_reason' => FirmwareUpdateFailureReason::Timeout,
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditLockerBank::class, ['record' => $bank->getRouteKey()])
            ->assertSee('ota_1')
            ->assertSee('0.0.9')
            ->assertSee('UPDATE_DOWNLOAD_FAILED')
            ->assertSee('Timeout');
    }

    public function test_the_update_action_is_offered_for_a_capable_bank_only(): void
    {
        $capable = $this->capableBank();
        $incapable = $this->capableBank(['firmware_update_capable' => false]);

        $test = Livewire::actingAs($this->admin())->test(ListLockerBanks::class);

        $test->assertTableActionVisible('updateFirmware', $capable);
        $test->assertTableActionHidden('updateFirmware', $incapable);

        Livewire::actingAs($this->admin())
            ->test(EditLockerBank::class, ['record' => $incapable->getRouteKey()])
            ->assertActionHidden('updateFirmware');
        Livewire::actingAs($this->admin())
            ->test(EditLockerBank::class, ['record' => $capable->getRouteKey()])
            ->assertActionVisible('updateFirmware');
    }

    public function test_an_admin_requests_an_update_to_the_chosen_release(): void
    {
        $bank = $this->capableBank();
        $release = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.0']);

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->callTableAction('updateFirmware', $bank, ['release_id' => $release->id, 'allow_downgrade' => false])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $bank->refresh();
        $this->assertSame(FirmwareUpdateStatus::Requested, $bank->firmware_update_status);
        $this->assertSame('0.2.0', $bank->firmware_update_target_version);
    }

    public function test_a_downgrade_needs_the_toggle(): void
    {
        $bank = $this->capableBank(['firmware_version' => '0.3.0']);
        $release = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.0']);

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->callTableAction('updateFirmware', $bank, ['release_id' => $release->id, 'allow_downgrade' => true])
            ->assertNotified();

        $this->assertSame('0.2.0', $bank->refresh()->firmware_update_target_version);
    }

    public function test_the_release_picker_lists_only_valid_releases(): void
    {
        $bank = $this->capableBank();
        $valid = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.0']);
        $invalid = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.1', 'invalid' => true]);

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->callTableAction('updateFirmware', $bank, ['release_id' => $invalid->id, 'allow_downgrade' => false])
            ->assertHasTableActionErrors(['release_id']);

        $this->assertNull($bank->refresh()->firmware_update_status);
        $this->assertNotSame($valid->id, $invalid->id);
    }

    public function test_a_refusal_is_shown_as_a_translated_notification_not_an_exception(): void
    {
        $bank = $this->capableBank(['firmware_update_status' => FirmwareUpdateStatus::Downloading]);
        $release = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.0']);

        app()->setLocale('de');

        Livewire::actingAs($this->admin())
            ->test(ListLockerBanks::class)
            ->callTableAction('updateFirmware', $bank, ['release_id' => $release->id, 'allow_downgrade' => false])
            ->assertNotified(__('Firmware update refused'));

        $this->assertSame(
            'Ein Firmware-Update dieser Schließfachanlage läuft bereits.',
            __('A firmware update of this locker bank is already in progress.'),
        );
        $this->assertSame(FirmwareUpdateStatus::Downloading, $bank->refresh()->firmware_update_status);
    }

    public function test_a_manager_cannot_reach_locker_banks_or_the_update_action(): void
    {
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();
        $bank = $this->capableBank();

        $this->actingAs($manager)->get(LockerBankResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager);
        $this->assertFalse(LockerBankResource::canAccess());
    }
}
