<?php

namespace Tests;

use App\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        foreach ([
            'APP_CONFIG_CACHE',
            'APP_EVENTS_CACHE',
            'APP_PACKAGES_CACHE',
            'APP_ROUTES_CACHE',
            'APP_SERVICES_CACHE',
        ] as $cacheEnvVar) {
            $cachePath = getenv($cacheEnvVar);

            if (is_string($cachePath) && $cachePath !== '' && file_exists($cachePath)) {
                unlink($cachePath);
            }
        }

        parent::setUp();

        // Keep projections/reactors synchronous in tests, even if local
        // docker-compose defaults use redis queues for app runtime.
        config()->set('queue.default', 'sync');
        config()->set('broadcasting.default', 'log');

        // Tests should not be blocked by API rate limiting.
        $this->withoutMiddleware(ThrottleRequests::class);

        // Erstellen Sie die SQLite-Datenbankdatei, falls sie nicht existiert
        if (! file_exists(database_path('test-database.sqlite'))) {
            touch(database_path('test-database.sqlite'));
        }

        // Führen Sie die Migrationen aus
        $this->artisan('migrate');

        $this->actWithinDefaultOrganization();
    }

    /**
     * Put the default organization in context, the way a single-organization
     * installation always is.
     *
     * Organization-owned models are scoped fail-closed: with nothing in context
     * they match no rows and cannot be created. That is deliberate for
     * production code, but a test that never mentions organizations is a test
     * about something else, and it should behave as the single-organization
     * case it was written for. Tests that care set their own context.
     */
    protected function actWithinDefaultOrganization(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Organization'],
        );

        app(OrganizationContext::class)->set($organization);
    }
}
