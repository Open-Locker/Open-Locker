<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppInfoControllerTest extends TestCase
{
    /**
     * Test the identify method of the AppInfoController.
     *
     * @return void
     */
    public function test_identify_endpoint_returns_expected_data()
    {
        $response = $this->getJson('/api/identify');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'name',
            'type',
            'api_version',
            'version',
            'identifier',
            'environment',
            'timestamp',
            'realtime',
        ]);

        $response->assertJson([
            'name' => 'Open-Locker',
            'type' => 'backend',
            'api_version' => 'v1',
            'identifier' => 'open-locker-backend',
        ]);
    }

    public function test_identify_advertises_public_reverb_settings(): void
    {
        config()->set('filament.broadcasting.echo.key', 'public-key');
        config()->set('filament.broadcasting.echo.wsHost', 'ws.example.com');
        config()->set('filament.broadcasting.echo.wsPort', '443');
        config()->set('filament.broadcasting.echo.forceTLS', true);

        $this->getJson('/api/identify')
            ->assertOk()
            ->assertJsonPath('realtime', [
                'key' => 'public-key',
                'host' => 'ws.example.com',
                'port' => 443,
                'scheme' => 'https',
            ]);
    }

    public function test_identify_advertises_plain_http_reverb_settings(): void
    {
        config()->set('filament.broadcasting.echo.key', 'public-key');
        config()->set('filament.broadcasting.echo.wsHost', 'localhost');
        config()->set('filament.broadcasting.echo.wsPort', '8080');
        config()->set('filament.broadcasting.echo.forceTLS', false);

        $this->getJson('/api/identify')
            ->assertOk()
            ->assertJsonPath('realtime', [
                'key' => 'public-key',
                'host' => 'localhost',
                'port' => 8080,
                'scheme' => 'http',
            ]);
    }

    /**
     * @return array<string, array{bool, string|null, int}>
     */
    public static function unsetPortProvider(): array
    {
        return [
            'https, port unset' => [true, null, 443],
            'http, port unset' => [false, null, 80],
            // A compose file passing `VITE_REVERB_PORT=${VITE_REVERB_PORT}` yields ''.
            'https, port empty' => [true, '', 443],
            'http, port empty' => [false, '', 80],
        ];
    }

    #[DataProvider('unsetPortProvider')]
    public function test_identify_defaults_the_reverb_port_to_the_scheme_port(bool $tls, ?string $port, int $expected): void
    {
        config()->set('filament.broadcasting.echo.key', 'public-key');
        config()->set('filament.broadcasting.echo.wsHost', 'ws.example.com');
        config()->set('filament.broadcasting.echo.wsPort', $port);
        config()->set('filament.broadcasting.echo.forceTLS', $tls);

        $this->getJson('/api/identify')
            ->assertOk()
            ->assertJsonPath('realtime.port', $expected);
    }

    public function test_identify_omits_realtime_without_a_public_reverb_host(): void
    {
        config()->set('filament.broadcasting.echo.key', 'public-key');
        config()->set('filament.broadcasting.echo.wsHost', null);

        $this->getJson('/api/identify')
            ->assertOk()
            ->assertJsonPath('realtime', null);
    }

    public function test_identify_omits_realtime_without_a_public_reverb_key(): void
    {
        config()->set('filament.broadcasting.echo.key', null);
        config()->set('filament.broadcasting.echo.wsHost', 'ws.example.com');

        $this->getJson('/api/identify')
            ->assertOk()
            ->assertJsonPath('realtime', null);
    }
}
