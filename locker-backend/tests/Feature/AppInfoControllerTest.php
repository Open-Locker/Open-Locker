<?php

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
        ]);

        $response->assertJson([
            'name' => 'Open-Locker',
            'type' => 'backend',
            'api_version' => 'v1',
            'identifier' => 'open-locker-backend',
        ]);
    }
}
