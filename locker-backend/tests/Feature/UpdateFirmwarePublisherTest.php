<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FirmwareRelease;
use App\Mqtt\Publishers\UpdateFirmwareCommandPublisher;
use App\Observability\MqttSpanAttributes;
use App\Reactors\MqttReactor;
use App\StorableEvents\FirmwareUpdateRequested;
use Database\Factories\FirmwareReleaseFactory;
use Database\Factories\LockerBankFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use PhpMqtt\Client\Facades\MQTT;
use Tests\Fakes\FakeMqttClient;
use Tests\Support\AssertsJsonSchemas;
use Tests\TestCase;

class UpdateFirmwarePublisherTest extends TestCase
{
    use AssertsJsonSchemas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // As in production: the public host, and https.
        URL::forceRootUrl('https://open-locker.cloud');
        URL::forceScheme('https');
    }

    private function event(FirmwareRelease $release, string $lockerBankUuid, bool $allowDowngrade = false): FirmwareUpdateRequested
    {
        return new FirmwareUpdateRequested(
            lockerBankUuid: $lockerBankUuid,
            commandId: '33333333-3333-3333-3333-333333333333',
            releaseId: (string) $release->id,
            version: $release->version,
            allowDowngrade: $allowDowngrade,
            actorUserId: null,
            requestedAtIso8601: now()->toIso8601String(),
        );
    }

    /**
     * @return list<string> every line written to the log while `$run` runs
     */
    private function logLinesDuring(callable $run): array
    {
        $lines = [];
        $record = static function (mixed $message, mixed $context = []) use (&$lines): void {
            $lines[] = (string) $message.' '.json_encode($context);
        };
        Log::shouldReceive('debug', 'info', 'notice', 'warning', 'error', 'critical')
            ->zeroOrMoreTimes()
            ->andReturnUsing($record);

        $run();

        return $lines;
    }

    public function test_update_firmware_publisher_builds_a_payload_that_matches_the_contract(): void
    {
        $mqttClient = new FakeMqttClient;
        MQTT::shouldReceive('connection')->once()->with('publisher')->andReturn($mqttClient);
        $bank = LockerBankFactory::new()->create();
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);

        app(UpdateFirmwareCommandPublisher::class)->publish($this->event($release, (string) $bank->id, true));

        $this->assertCount(1, $mqttClient->published);
        $this->assertSame("locker/{$bank->id}/command", $mqttClient->published[0]['topic']);

        $payload = $this->assertPublishedPayloadMatchesSchema($mqttClient, 'payloads/command-update-firmware.json');

        $this->assertSame('update_firmware', $payload['action']);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $payload['transaction_id']);
        $this->assertSame('0.2.0', $payload['data']['version']);
        $this->assertTrue($payload['data']['allow_downgrade']);

        // A link the locker can use: signed for this release and bank, and short enough for the firmware (ADR-0076).
        $url = $payload['data']['url'];
        $this->assertLessThanOrEqual(FirmwareRelease::DOWNLOAD_URL_MAX_LENGTH, strlen($url));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertStringContainsString("/{$release->id}/{$bank->id}?", $url);
    }

    public function test_no_log_line_carries_the_download_link_or_its_signature(): void
    {
        $mqttClient = new FakeMqttClient;
        MQTT::shouldReceive('connection')->andReturn($mqttClient);
        $bank = LockerBankFactory::new()->create();
        $release = FirmwareReleaseFactory::new()->create(['version' => '0.2.0']);

        $lines = $this->logLinesDuring(function () use ($release, $bank): void {
            app(UpdateFirmwareCommandPublisher::class)->publish($this->event($release, (string) $bank->id));
        });

        $this->assertNotEmpty($lines, 'The publisher logs nothing, so this test would pass for any content.');
        $url = $this->assertPublishedPayloadMatchesSchema($mqttClient, 'payloads/command-update-firmware.json')['data']['url'];
        $signature = (string) preg_replace('/^.*signature=/', '', $url);
        $this->assertSame(64, strlen($signature));
        foreach ($lines as $line) {
            $this->assertStringNotContainsString($signature, $line);
            $this->assertStringNotContainsString('signature=', $line);
            $this->assertStringNotContainsString('expires=', $line);
            $this->assertStringNotContainsString('https://', $line);
        }
    }

    public function test_nothing_is_sent_when_the_link_would_not_be_https_or_would_be_too_long(): void
    {
        $mqttClient = new FakeMqttClient;
        MQTT::shouldReceive('connection')->never();
        $bank = LockerBankFactory::new()->create();
        $release = FirmwareReleaseFactory::new()->create();

        $lines = $this->logLinesDuring(function () use ($release, $bank): void {
            foreach (['http://open-locker.cloud' => 'http', 'https://'.str_repeat('a', 70).'.open-locker.example.com' => 'https'] as $root => $scheme) {
                URL::forceRootUrl($root);
                URL::forceScheme($scheme);

                app(UpdateFirmwareCommandPublisher::class)->publish($this->event($release, (string) $bank->id));
            }
        });

        $this->assertCount(2, $lines, 'Each refusal is logged.');
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('signature', $line);
            $this->assertStringNotContainsString('expires=', $line);
        }

        $this->assertSame([], $mqttClient->published);
    }

    public function test_the_mqtt_client_does_not_log_what_it_publishes(): void
    {
        // php-mqtt writes every published message, link included, to the log at debug level when this is on.
        foreach (['default', 'publisher', 'listener'] as $connection) {
            if (config("mqtt-client.connections.{$connection}") !== null) {
                $this->assertFalse((bool) config("mqtt-client.connections.{$connection}.enable_logging"), $connection);
            }
        }
    }

    public function test_the_trace_attributes_of_the_publish_leave_out_the_link(): void
    {
        $attributes = MqttSpanAttributes::fromMessage('locker/abc/command', [
            'message_id' => 'm',
            'transaction_id' => 't',
            'action' => 'update_firmware',
            'data' => ['version' => '0.2.0', 'url' => 'https://open-locker.cloud/api/firmware/r/b?expires=1&signature=secret'],
        ]);

        $this->assertStringNotContainsString('secret', (string) json_encode($attributes));
    }

    public function test_nothing_is_sent_for_a_release_that_is_gone_or_invalid(): void
    {
        $mqttClient = new FakeMqttClient;
        MQTT::shouldReceive('connection')->never();
        $bank = LockerBankFactory::new()->create();
        $invalid = FirmwareReleaseFactory::new()->create(['invalid' => true]);
        $gone = FirmwareReleaseFactory::new()->create();
        $goneEvent = $this->event($gone, (string) $bank->id);
        $gone->delete();

        app(UpdateFirmwareCommandPublisher::class)->publish($this->event($invalid, (string) $bank->id));
        app(UpdateFirmwareCommandPublisher::class)->publish($goneEvent);

        $this->assertSame([], $mqttClient->published);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertPublishedPayloadMatchesSchema(FakeMqttClient $mqttClient, string $schemaPath): array
    {
        $publishedPayload = json_decode($mqttClient->published[0]['payload'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($publishedPayload);
        $this->assertIsString($publishedPayload['message_id'] ?? null);
        $this->assertIsString($publishedPayload['timestamp'] ?? null);

        $this->assertMatchesAsyncApiSchema($publishedPayload, $schemaPath);

        return $publishedPayload;
    }

    public function test_the_reactor_publishes_a_requested_update(): void
    {
        $this->mock(UpdateFirmwareCommandPublisher::class, function ($mock): void {
            $mock->shouldReceive('publish')->once();
        });
        $bank = LockerBankFactory::new()->create();
        $release = FirmwareReleaseFactory::new()->create();

        app(MqttReactor::class)->onFirmwareUpdateRequested($this->event($release, (string) $bank->id));
    }
}
