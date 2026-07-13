<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_legacy_locale_prefixed_admin_urls_redirect_to_single_panel(): void
    {
        $this->get('/en/admin')->assertMovedPermanently()->assertRedirect('/admin');
        $this->get('/de/admin/login')->assertMovedPermanently()->assertRedirect('/admin/login');
    }
}
