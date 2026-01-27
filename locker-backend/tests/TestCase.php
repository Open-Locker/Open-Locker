<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
