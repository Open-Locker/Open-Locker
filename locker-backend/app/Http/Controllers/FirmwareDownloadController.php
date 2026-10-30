<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FirmwareRelease;
use App\Models\LockerBank;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FirmwareDownloadController extends Controller
{
    /**
     * Streams a firmware image to the locker that holds a signed link to it.
     *
     * The `signed` middleware is the only authentication, so there is no Policy:
     * an expired or altered link never reaches this method (403), and a bank
     * that was deleted is a 404. Only lockers use it, so it
     * stays out of the OpenAPI document the mobile client is generated from.
     */
    #[ExcludeRouteFromDocs]
    public function __invoke(FirmwareRelease $release, LockerBank $lockerBank): StreamedResponse
    {
        // A valid link is not enough: the bank must be installing exactly this release. A link that
        // outlives its update, or was made for another bank's update, is refused like any bad link.
        abort_unless($lockerBank->isDownloadingRelease($release), 403);
        abort_if($release->invalid || ! Storage::exists($release->file_path), 404);

        return Storage::download($release->file_path, "locker-firmware-{$release->version}.bin");
    }
}
