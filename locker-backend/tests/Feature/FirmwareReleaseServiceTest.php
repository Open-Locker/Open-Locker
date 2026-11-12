<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FirmwareUpdateStatus;
use App\Models\FirmwareRelease;
use App\Services\FirmwareReleaseService;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FirmwareReleaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TEMP = 'firmware-uploads/tmp.bin';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
    }

    private function service(): FirmwareReleaseService
    {
        return app(FirmwareReleaseService::class);
    }

    public function test_upload_stores_the_image_and_removes_the_temporary_file(): void
    {
        $bytes = "\xE9IMAGE";
        Storage::put(self::TEMP, $bytes);

        $release = $this->service()->upload('0.2.0', self::TEMP, null);

        $this->assertSame(hash('sha256', $bytes), $release->sha256);
        $this->assertSame(6, $release->size_bytes);
        $this->assertSame($bytes, Storage::get('firmware/0.2.0.bin'));
        Storage::assertMissing(self::TEMP);
    }

    public function test_a_file_without_the_esp32_image_magic_is_refused_and_the_upload_removed(): void
    {
        Storage::put(self::TEMP, 'not an image');

        try {
            $this->service()->upload('0.2.0', self::TEMP, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.image', $e->errors());
        }

        $this->assertSame(0, FirmwareRelease::query()->count());
        Storage::assertMissing(self::TEMP);
        $this->assertSame([], Storage::allFiles('firmware'));
    }

    public function test_an_empty_or_oversize_file_is_refused_and_the_upload_removed(): void
    {
        Storage::put(self::TEMP, '');
        try {
            $this->service()->upload('0.2.0', self::TEMP, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }
        Storage::assertMissing(self::TEMP);

        Storage::put(self::TEMP, "\xE9".str_repeat('A', FirmwareRelease::MAX_IMAGE_BYTES));
        try {
            $this->service()->upload('0.2.0', self::TEMP, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }
        Storage::assertMissing(self::TEMP);
        $this->assertSame(0, FirmwareRelease::query()->count());
    }

    public function test_a_duplicate_version_leaves_the_first_release_and_its_file_intact(): void
    {
        $first = FirmwareReleaseFactory::new()->withImage("\xE9FIRST")->create(['version' => '0.2.0']);
        Storage::put(self::TEMP, "\xE9SECOND");

        try {
            $this->service()->upload('0.2.0', self::TEMP, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.version', $e->errors());
        }

        $this->assertSame("\xE9FIRST", Storage::get($first->file_path));
        $this->assertSame(hash('sha256', "\xE9FIRST"), $first->refresh()->sha256);
        $this->assertSame(1, FirmwareRelease::query()->count());
        Storage::assertMissing(self::TEMP);
    }

    public function test_an_existing_file_is_never_overwritten_and_no_row_is_kept(): void
    {
        Storage::put('firmware/0.3.0.bin', 'ORPHAN');
        Storage::put(self::TEMP, "\xE9NEW");

        try {
            $this->service()->upload('0.3.0', self::TEMP, null);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.version', $e->errors());
        }

        $this->assertSame('ORPHAN', Storage::get('firmware/0.3.0.bin'));
        $this->assertSame(0, FirmwareRelease::query()->count());
        Storage::assertMissing(self::TEMP);
    }

    public function test_mark_invalid(): void
    {
        $release = FirmwareReleaseFactory::new()->create();

        $this->service()->markInvalid($release);

        $this->assertTrue($release->refresh()->invalid);
    }

    public function test_delete_removes_the_row_and_the_file_unless_in_use(): void
    {
        $unused = FirmwareReleaseFactory::new()->withImage()->create();
        $inUse = FirmwareReleaseFactory::new()->withImage()->create();
        LockerBankFactory::new()->create([
            'firmware_update_status' => FirmwareUpdateStatus::Requested,
            'firmware_update_release_id' => $inUse->id,
        ]);

        $this->service()->delete($unused);
        Storage::assertMissing($unused->file_path);
        $this->assertNull(FirmwareRelease::query()->find($unused->id));

        try {
            $this->service()->delete($inUse);
            $this->fail('Expected a refusal.');
        } catch (\LogicException) {
        }
        Storage::assertExists($inUse->file_path);
        $this->assertNotNull(FirmwareRelease::query()->find($inUse->id));
    }
}
