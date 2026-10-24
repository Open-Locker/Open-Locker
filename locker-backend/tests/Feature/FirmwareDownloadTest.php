<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FirmwareUpdateStatus;
use App\Models\FirmwareRelease;
use App\Models\LockerBank;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A locker fetches its firmware image from a link that the backend signs and
 * sends over MQTT (ADR-0077). The link is the credential, and it works only
 * while the bank has that update in flight.
 */
class FirmwareDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        RateLimiter::clear('firmware-download');
    }

    /** A bank whose update to `$release` is in the given state. */
    private function bankUpdating(FirmwareRelease $release, FirmwareUpdateStatus $status = FirmwareUpdateStatus::Requested): LockerBank
    {
        return LockerBankFactory::new()->create([
            'firmware_update_status' => $status,
            'firmware_update_release_id' => $release->id,
        ]);
    }

    public function test_a_valid_link_streams_the_image(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage('IMAGE-BYTES')->create(['version' => '0.2.0']);
        $lockerBank = $this->bankUpdating($release);

        $response = $this->get($release->signedDownloadUrl((string) $lockerBank->id));

        $response->assertOk();
        $this->assertSame('IMAGE-BYTES', $response->streamedContent());
        $response->assertHeader('Content-Length', (string) strlen('IMAGE-BYTES'));
    }

    public function test_the_link_works_while_the_update_is_requested_or_downloading_and_not_after(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();

        $this->get($release->signedDownloadUrl((string) $this->bankUpdating($release, FirmwareUpdateStatus::Downloading)->id))->assertOk();
        $this->get($release->signedDownloadUrl((string) $this->bankUpdating($release, FirmwareUpdateStatus::Requested)->id))->assertOk();
        $this->get($release->signedDownloadUrl((string) $this->bankUpdating($release, FirmwareUpdateStatus::Failed)->id))->assertForbidden();
    }

    public function test_a_valid_link_is_forbidden_for_a_bank_with_no_update_for_that_release(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $other = FirmwareReleaseFactory::new()->withImage('OTHER')->create();

        $idle = LockerBankFactory::new()->create();
        $this->get($release->signedDownloadUrl((string) $idle->id))->assertForbidden();

        // A bank updating to another release cannot fetch this one with a link made for it.
        $updatingOther = $this->bankUpdating($other);
        $this->get($release->signedDownloadUrl((string) $updatingOther->id))->assertForbidden();
    }

    public function test_an_expired_link_is_forbidden(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $url = $release->signedDownloadUrl((string) $this->bankUpdating($release)->id);

        $this->travel(FirmwareRelease::DOWNLOAD_LINK_MINUTES + 1)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_a_link_is_still_valid_just_before_it_expires(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $url = $release->signedDownloadUrl((string) $this->bankUpdating($release)->id);

        $this->travel(FirmwareRelease::DOWNLOAD_LINK_MINUTES - 1)->minutes();

        $this->get($url)->assertOk();
    }

    public function test_a_tampered_link_is_forbidden(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $other = FirmwareReleaseFactory::new()->withImage('OTHER')->create();
        $lockerBank = $this->bankUpdating($release);
        $url = $release->signedDownloadUrl((string) $lockerBank->id);

        // Another release under the same signature, another locker, a longer expiry, no signature.
        $this->get(str_replace((string) $release->id, (string) $other->id, $url))->assertForbidden();
        $this->get(str_replace((string) $lockerBank->id, (string) LockerBankFactory::new()->create()->id, $url))->assertForbidden();
        $this->get((string) preg_replace('/expires=\d+/', 'expires='.(now()->addYear()->timestamp), $url))->assertForbidden();
        $this->get((string) preg_replace('/&signature=[0-9a-f]+/', '', $url))->assertForbidden();
    }

    public function test_a_link_for_a_deleted_bank_is_not_found(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $lockerBank = $this->bankUpdating($release);
        $url = $release->signedDownloadUrl((string) $lockerBank->id);
        $lockerBank->delete();

        $this->get($url)->assertNotFound();
    }

    public function test_a_link_to_a_release_marked_invalid_is_not_found(): void
    {
        $release = FirmwareReleaseFactory::new()->withImage()->create(['invalid' => true]);

        $this->get($release->signedDownloadUrl((string) $this->bankUpdating($release)->id))->assertNotFound();
    }

    public function test_a_link_to_a_release_without_a_stored_file_is_not_found(): void
    {
        $release = FirmwareReleaseFactory::new()->create();

        $this->get($release->signedDownloadUrl((string) $this->bankUpdating($release)->id))->assertNotFound();
    }

    public function test_a_device_can_download_and_retry_but_a_bank_cannot_hammer_the_route(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $release = FirmwareReleaseFactory::new()->withImage()->create();
        $url = $release->signedDownloadUrl((string) $this->bankUpdating($release)->id);

        // One download and a few retries after a dropped connection.
        foreach (range(1, 5) as $ignored) {
            $this->get($url)->assertOk();
        }

        $statuses = [];
        foreach (range(1, 20) as $ignored) {
            $statuses[] = $this->get($url)->getStatusCode();
        }
        $this->assertContains(429, $statuses);

        // The limit is per bank, so one bank's retries do not block another's download.
        $otherBank = $this->bankUpdating($release);
        $this->get($release->signedDownloadUrl((string) $otherBank->id))->assertOk();
    }

    public function test_the_link_fits_the_256_characters_the_firmware_accepts(): void
    {
        // As in production, where the scheme is forced (AppServiceProvider) and the host is the public one.
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
        $release = FirmwareReleaseFactory::new()->create();

        $url = $release->signedDownloadUrl((string) LockerBankFactory::new()->create()->id);

        $this->assertLessThanOrEqual(256, strlen($url));
        $this->assertTrue(FirmwareRelease::isUsableDownloadUrl($url));
        $this->assertStringStartsWith('https://', $url);
    }

    public function test_a_download_url_must_be_https_and_at_most_256_characters(): void
    {
        $this->assertTrue(FirmwareRelease::isUsableDownloadUrl('https://open-locker.cloud/api/firmware/x'));
        $this->assertTrue(FirmwareRelease::isUsableDownloadUrl('https://'.str_repeat('a', 248)));
        $this->assertFalse(FirmwareRelease::isUsableDownloadUrl('https://'.str_repeat('a', 249)));
        $this->assertFalse(FirmwareRelease::isUsableDownloadUrl('http://open-locker.cloud/api/firmware/x'));
        $this->assertFalse(FirmwareRelease::isUsableDownloadUrl('HTTPS://open-locker.cloud/x'));
        $this->assertFalse(FirmwareRelease::isUsableDownloadUrl(''));
    }

    public function test_a_release_needs_a_semantic_version(): void
    {
        foreach (['v1.0.0', '1.0', '01.2.3', '1.0.0+build', '', str_repeat('1', 32).'.0.0'] as $version) {
            try {
                FirmwareReleaseFactory::new()->create(['version' => $version]);
                $this->fail("Version '{$version}' was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, FirmwareRelease::query()->count());
        FirmwareReleaseFactory::new()->create(['version' => '1.0.0-rc.1']);
        $this->assertSame(1, FirmwareRelease::query()->count());
    }

    public function test_a_version_can_be_released_once(): void
    {
        FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);

        $this->expectException(UniqueConstraintViolationException::class);
        FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);
    }
}
