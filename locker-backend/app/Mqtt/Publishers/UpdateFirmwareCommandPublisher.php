<?php

declare(strict_types=1);

namespace App\Mqtt\Publishers;

use App\Models\FirmwareRelease;
use App\Mqtt\MqttPublisher;
use App\StorableEvents\FirmwareUpdateRequested;
use Illuminate\Support\Facades\Log;

class UpdateFirmwareCommandPublisher
{
    public function __construct(
        private readonly MqttPublisher $mqttPublisher,
    ) {}

    /**
     * Sends update_firmware with a download link made now.
     *
     * The link's query string is a credential (ADR-0077): it is built here, put
     * on the device's private command topic, and kept out of the log lines
     * below, of the event, and of the queued job, which holds only the event.
     */
    public function publish(FirmwareUpdateRequested $event): void
    {
        $topic = "locker/{$event->lockerBankUuid}/command";
        $release = FirmwareRelease::query()->find($event->releaseId);

        if ($release === null || $release->invalid) {
            Log::error('[UpdateFirmwareCommandPublisher] The release is gone or invalid; update_firmware was not sent.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'transactionId' => $event->commandId,
                'releaseId' => $event->releaseId,
            ]);

            return;
        }

        $url = $release->signedDownloadUrl($event->lockerBankUuid);

        // The request was checked for this when it was made; the public URL may have changed since.
        // The link itself stays out of the log.
        if (! FirmwareRelease::isUsableDownloadUrl($url)) {
            Log::error('[UpdateFirmwareCommandPublisher] The download link is not an https URL of at most 256 characters; update_firmware was not sent.', [
                'lockerBankUuid' => $event->lockerBankUuid,
                'transactionId' => $event->commandId,
                'releaseId' => $event->releaseId,
                'linkLength' => strlen($url),
            ]);

            return;
        }

        Log::info('[UpdateFirmwareCommandPublisher] Publishing update_firmware command.', [
            'topic' => $topic,
            'lockerBankUuid' => $event->lockerBankUuid,
            'transactionId' => $event->commandId,
            'releaseId' => $event->releaseId,
            'version' => $release->version,
        ]);

        $this->mqttPublisher->publish($topic, [
            'action' => 'update_firmware',
            'transaction_id' => $event->commandId,
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'version' => $release->version,
                'url' => $url,
                'allow_downgrade' => $event->allowDowngrade,
            ],
        ]);
    }
}
