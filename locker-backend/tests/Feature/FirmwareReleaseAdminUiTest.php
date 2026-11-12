<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\FirmwareUpdateStatus;
use App\Enums\Role;
use App\Filament\Resources\FirmwareReleaseResource;
use App\Filament\Resources\FirmwareReleaseResource\Pages\CreateFirmwareRelease;
use App\Filament\Resources\FirmwareReleaseResource\Pages\ListFirmwareReleases;
use App\Models\FirmwareRelease;
use App\Models\User;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Operators upload signed firmware images and mark bad ones invalid (ADR-0077).
 * Like the locker bank settings, it is admin-only (lockerbank.configure).
 */
class FirmwareReleaseAdminUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function manager(): User
    {
        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, null, now())
            ->persist();
        $manager->flushPermissionCache();

        return $manager;
    }

    public function test_an_admin_can_load_the_release_list_and_upload_pages(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(FirmwareReleaseResource::getUrl('index'))->assertOk();
        $this->actingAs($admin)->get(FirmwareReleaseResource::getUrl('create'))->assertOk();
    }

    public function test_a_manager_cannot_see_or_reach_firmware_releases(): void
    {
        $this->admin();
        $manager = $this->manager();

        $this->actingAs($manager)->get(FirmwareReleaseResource::getUrl('index'))->assertForbidden();
        $this->actingAs($manager)->get(FirmwareReleaseResource::getUrl('create'))->assertForbidden();
        $this->actingAs($manager);
        $this->assertFalse(FirmwareReleaseResource::canAccess());
        $this->assertFalse(FirmwareReleaseResource::shouldRegisterNavigation());
    }

    public function test_an_upload_stores_the_image_and_computes_its_hash_and_size_on_the_server(): void
    {
        $admin = $this->admin();
        $bytes = "\xE9SIGNED-IMAGE\x00\x01\x02";

        Livewire::actingAs($admin)
            ->test(CreateFirmwareRelease::class)
            ->fillForm([
                'version' => '0.2.0',
                'image' => UploadedFile::fake()->createWithContent('anything.bin', $bytes),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $release = FirmwareRelease::query()->where('version', '0.2.0')->sole();
        $this->assertSame(hash('sha256', $bytes), $release->sha256);
        $this->assertSame(strlen($bytes), $release->size_bytes);
        $this->assertSame($admin->id, $release->uploaded_by_user_id);
        $this->assertSame('firmware/0.2.0.bin', $release->file_path);
        $this->assertSame($bytes, Storage::get($release->file_path));
        $this->assertFalse($release->invalid);
        // Only the stored image remains, not the temporary upload.
        $this->assertSame(['firmware/0.2.0.bin'], Storage::allFiles('firmware'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badVersions(): array
    {
        return [
            'leading v' => ['v1.0.0'],
            'two parts' => ['1.0'],
            'leading zero' => ['01.2.3'],
            'build metadata' => ['1.0.0+build'],
            'too long' => [str_repeat('1', 32).'.0.0'],
        ];
    }

    #[DataProvider('badVersions')]
    public function test_a_version_that_is_not_semantic_versioning_is_refused(string $version): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFirmwareRelease::class)
            ->fillForm(['version' => $version, 'image' => UploadedFile::fake()->createWithContent('a.bin', 'X')])
            ->call('create')
            ->assertHasFormErrors(['version']);

        $this->assertSame(0, FirmwareRelease::query()->count());
    }

    public function test_a_version_can_be_uploaded_once(): void
    {
        FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);

        Livewire::actingAs($this->admin())
            ->test(CreateFirmwareRelease::class)
            ->fillForm(['version' => '0.2.0', 'image' => UploadedFile::fake()->createWithContent('a.bin', 'X')])
            ->call('create')
            ->assertHasFormErrors(['version']);

        $this->assertSame(1, FirmwareRelease::query()->count());
    }

    public function test_an_image_larger_than_the_ota_slot_is_refused(): void
    {
        $this->assertSame(0x1D0000, FirmwareRelease::MAX_IMAGE_BYTES);
        $tooBig = (int) ceil((FirmwareRelease::MAX_IMAGE_BYTES + 1) / 1024);

        Livewire::actingAs($this->admin())
            ->test(CreateFirmwareRelease::class)
            ->fillForm(['version' => '0.2.0', 'image' => UploadedFile::fake()->create('big.bin', $tooBig)])
            ->call('create')
            ->assertHasFormErrors(['image']);

        $this->assertSame(0, FirmwareRelease::query()->count());
    }

    public function test_a_file_that_is_not_an_esp32_image_is_refused_with_a_translated_message(): void
    {
        app()->setLocale('de');

        Livewire::actingAs($this->admin())
            ->test(CreateFirmwareRelease::class)
            ->fillForm(['version' => '0.2.0', 'image' => UploadedFile::fake()->createWithContent('a.bin', 'plain text')])
            ->call('create')
            ->assertHasFormErrors(['image'])
            ->assertSee('kein ESP32-Firmware-Image');

        $this->assertSame(0, FirmwareRelease::query()->count());
        $this->assertSame([], Storage::allFiles('firmware-uploads'));
        $this->assertSame([], Storage::allFiles('firmware'));
    }

    public function test_an_upload_needs_an_image(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFirmwareRelease::class)
            ->fillForm(['version' => '0.2.0'])
            ->call('create')
            ->assertHasFormErrors(['image' => 'required']);
    }

    public function test_the_list_shows_releases_and_a_release_can_be_marked_invalid(): void
    {
        $admin = $this->admin();
        $release = FirmwareReleaseFactory::new()->withImage()->create(['version' => '0.2.0']);

        $test = Livewire::actingAs($admin)->test(ListFirmwareReleases::class);
        $test->assertCanSeeTableRecords([$release]);
        $test->assertTableActionVisible('markInvalid', $release);

        $test->callTableAction('markInvalid', $release);

        $this->assertTrue($release->refresh()->invalid);
        $test->assertTableActionHidden('markInvalid', $release);
    }

    public function test_the_model_refuses_to_delete_a_release_in_use_even_without_the_ui(): void
    {
        $inUse = FirmwareReleaseFactory::new()->create();
        LockerBankFactory::new()->create([
            'firmware_update_status' => FirmwareUpdateStatus::Requested,
            'firmware_update_release_id' => $inUse->id,
        ]);

        $this->expectException(\LogicException::class);

        $inUse->delete();
    }

    public function test_a_release_in_use_by_an_update_in_flight_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $inUse = FirmwareReleaseFactory::new()->withImage()->create();
        LockerBankFactory::new()->create([
            'firmware_update_status' => FirmwareUpdateStatus::Downloading,
            'firmware_update_release_id' => $inUse->id,
        ]);
        $finished = FirmwareReleaseFactory::new()->withImage()->create();
        LockerBankFactory::new()->create([
            'firmware_update_status' => FirmwareUpdateStatus::Completed,
            'firmware_update_release_id' => $finished->id,
        ]);
        $unused = FirmwareReleaseFactory::new()->withImage()->create();

        $test = Livewire::actingAs($admin)->test(ListFirmwareReleases::class);

        $test->assertTableActionHidden('delete', $inUse);
        $test->assertTableActionVisible('delete', $finished);
        $test->assertTableActionVisible('delete', $unused);

        $test->callTableAction('delete', $unused);

        $this->assertNull(FirmwareRelease::query()->find($unused->id));
        Storage::assertMissing($unused->file_path);
        $this->assertNotNull(FirmwareRelease::query()->find($inUse->id));
        Storage::assertExists($inUse->file_path);
    }
}
