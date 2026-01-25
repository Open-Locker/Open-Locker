<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $basePath = Application::inferBasePath();
        $envFile = $basePath.'/.env';

        if (! file_exists($envFile)) {
            touch($envFile);
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Erstellen Sie die SQLite-Datenbankdatei, falls sie nicht existiert
        if (! file_exists(database_path('test-database.sqlite'))) {
            touch(database_path('test-database.sqlite'));
        }

        // Führen Sie die Migrationen aus
        $this->artisan('migrate');
    }
}
