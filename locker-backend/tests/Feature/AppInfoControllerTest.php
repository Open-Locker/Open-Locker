<?php

declare(strict_types=1);

namespace Tests\Feature;

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
