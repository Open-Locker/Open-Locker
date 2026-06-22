<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Lightweight API route that simply reports the resolved locale so the
        // SetLocale middleware (ADR-0024) can be asserted in isolation.
        Route::middleware('api')->get('/api/_test/locale', fn () => ['locale' => App::getLocale()]);
    }

    public function test_accept_language_header_sets_supported_locale(): void
    {
        $this->getJson('/api/_test/locale', ['Accept-Language' => 'de'])
            ->assertOk()
            ->assertJson(['locale' => 'de']);
    }

    public function test_language_range_is_matched_to_supported_locale(): void
    {
        $this->getJson('/api/_test/locale', ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'])
            ->assertOk()
            ->assertJson(['locale' => 'de']);
    }

    public function test_missing_header_keeps_default_locale(): void
    {
        $this->getJson('/api/_test/locale')
            ->assertOk()
            ->assertJson(['locale' => config('app.locale')]);
    }

    public function test_unsupported_header_falls_back_to_supported_locale(): void
    {
        $this->getJson('/api/_test/locale', ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertJson(['locale' => 'en']);
    }
}
