<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SetPanelLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_in_session_locale(): void
    {
        $this->withSession(['locale' => 'de'])
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'de'));

        $this->assertSame('de', app()->getLocale());

        $this->withSession(['locale' => 'en'])
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'en'));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_switcher_link_switches_locale_and_redirects_back(): void
    {
        $this->from('/admin/login')
            ->get(route('locale.switch', ['locale' => 'de']))
            ->assertRedirect('/admin/login')
            ->assertSessionHas('locale', 'de')
            ->assertCookie('locale', 'de');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'de'));
    }

    public function test_switcher_rejects_external_referer_and_persists_locale(): void
    {
        $this->withHeader('Referer', 'https://attacker.example/phishing')
            ->get(route('locale.switch', ['locale' => 'de']))
            ->assertRedirect('/admin')
            ->assertSessionHas('locale', 'de')
            ->assertCookie('locale', 'de');
    }

    public function test_switcher_rejects_unsupported_locale(): void
    {
        $this->get(route('locale.switch', ['locale' => 'fr']))->assertNotFound();
    }

    public function test_login_page_shows_inline_switcher(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(route('locale.switch', ['locale' => 'de']));
    }

    public function test_authenticated_page_renders_in_session_locale(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        $this->actingAs($user)
            ->withSession(['locale' => 'de'])
            ->get('/admin');

        $this->assertSame('de', app()->getLocale());
    }

    public function test_livewire_updates_keep_the_session_locale(): void
    {
        // Livewire updates skip panel middleware unless it is persistent, and
        // would then render tabs and filters in APP_LOCALE.
        $this->assertContains(SetPanelLocale::class, Livewire::getPersistentMiddleware());
    }

    public function test_livewire_update_request_renders_in_the_session_locale(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $page = $this->actingAs($admin)
            ->withSession(['locale' => 'de'])
            ->get(route('filament.admin.resources.audit-logs.index'))
            ->assertOk()
            ->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $matches);
        $snapshot = collect($matches[1])
            ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
            ->first(fn (string $json): bool => str_contains($json, 'ListAuditLog'));
        $this->assertNotNull($snapshot);

        // Every fallback says English: the app default and the browser. Only the
        // session locale, applied by SetPanelLocale on the update, says German.
        config(['app.locale' => 'en']);
        App::setLocale('en');

        $html = $this->withHeaders(['X-Livewire' => 'true', 'Accept-Language' => 'en'])
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [['snapshot' => $snapshot, 'updates' => ['activeTab' => 'access'], 'calls' => []]],
            ])
            ->assertOk()
            ->json('components.0.effects.html');

        // Filament builds the tabs before Livewire restores the snapshot locale,
        // so only the tab labels show whether SetPanelLocale ran.
        preg_match_all('/fi-tabs-item-label[^>]*>\s*([^<]+?)\s*</', $html, $tabs);
        $this->assertContains(__('Access', locale: 'de'), $tabs[1]);
    }

    public function test_login_page_renders_in_cookie_locale_without_session(): void
    {
        $this->withCookie('locale', 'de')
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'de'));

        $this->assertSame('de', app()->getLocale());
    }

    public function test_first_visit_negotiates_locale_from_accept_language(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'de'));

        $this->assertSame('de', app()->getLocale());
    }

    public function test_invalid_cookie_falls_back_to_browser_then_default_locale(): void
    {
        $this->withCookie('locale', 'fr')
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'de'));

        $this->assertSame('de', app()->getLocale());

        $this->withCookie('locale', 'fr')
            ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
            ->get('/admin/login')
            ->assertOk()
            ->assertSee(__('filament-panels::auth/pages/login.heading', locale: 'en'));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_legacy_locale_prefixed_admin_urls_redirect_to_single_panel(): void
    {
        $this->get('/en/admin')->assertMovedPermanently()->assertRedirect('/admin');
        $this->get('/de/admin/login')->assertMovedPermanently()->assertRedirect('/admin/login');
    }

    public function test_legacy_locale_prefixed_admin_urls_preserve_deep_paths_and_queries(): void
    {
        $this->get('/de/admin/password-reset/reset?token=secret-token&email=user%40example.com')
            ->assertMovedPermanently()
            ->assertRedirect('/admin/password-reset/reset?token=secret-token&email=user%40example.com');

        $this->get('/en/admin/users/42/edit?tab=permissions&filter%5Bactive%5D=1')
            ->assertMovedPermanently()
            ->assertRedirect('/admin/users/42/edit?tab=permissions&filter%5Bactive%5D=1');
    }
}
