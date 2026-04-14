<?php

namespace Tests;

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
    }
}
