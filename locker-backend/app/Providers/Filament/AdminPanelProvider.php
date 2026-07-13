<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Register;
use App\Http\Middleware\SetPanelLocale;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PDOException;

class AdminPanelProvider extends PanelProvider
{
    protected string $locale = 'en';

    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel(
            $panel->default()->id('admin')->path('en/admin'),
        );
    }

    protected function configurePanel(Panel $panel): Panel
    {
        try {
            if (Schema::hasTable('users') && User::count() === 0) {
                $panel->registration(Register::class);
            }
        } catch (QueryException|PDOException) {
            // During image builds/package discovery, database access may be unavailable.
        }

        return $panel
            ->login()
            ->emailVerification()
            ->passwordReset()
            ->profile(EditProfile::class)
            ->colors([
                'primary' => [
                    50 => 'oklch(0.97 0.03 263.785)',
                    100 => 'oklch(0.93 0.06 263.785)',
                    200 => 'oklch(0.87 0.11 263.785)',
                    300 => 'oklch(0.79 0.16 263.785)',
                    400 => 'oklch(0.69 0.21 263.785)',
                    500 => 'oklch(0.561 0.241 263.785)',
                    600 => 'oklch(0.5 0.22 263.785)',
                    700 => 'oklch(0.43 0.19 263.785)',
                    800 => 'oklch(0.36 0.15 263.785)',
                    900 => 'oklch(0.3 0.11 263.785)',
                    950 => 'oklch(0.24 0.08 263.785)',
                ],
            ])
            ->brandName('')
            ->brandLogo(fn (): HtmlString => new HtmlString(view('filament.brand')->render()))
            ->brandLogoHeight('3rem')
            ->favicon(asset('storage/assets/logo.svg', App::isProduction()))
            ->maxContentWidth(Width::Full)
            ->navigationGroups([
                NavigationGroup::make(fn () => __('Operations')),
                NavigationGroup::make(fn () => __('Setup')),
                NavigationGroup::make(fn () => __('Docs/Legal')),
            ])
            ->sidebarWidth('300px')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // No dashboard. With `/` free, Filament auto-registers a `home` route
            // that redirects to the first navigation item — the Compartments list
            // (Operations group, sort 10). The post-login redirect and brand-logo
            // link both resolve through this `home` route, so they land on the
            // current panel's Compartments index, locale-correct (en→en, de→de).
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): \Illuminate\Contracts\View\View => view('filament.locale-switcher', ['locale' => $this->locale])
            )
            // The topbar user menu shows only an avatar; surface the signed-in
            // user's name + email as a hover tooltip on it.
            ->renderHook(
                PanelsRenderHook::USER_MENU_AFTER,
                fn (): \Illuminate\Contracts\View\View => view('filament.user-menu-tooltip')
            )
            // The user menu does not exist on the pre-auth SimplePage layout
            // (login, password reset, register, email verification), so render
            // the switcher there too.
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.locale-switcher', ['locale' => $this->locale, 'center' => true])
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.realtime-compartment-open-notifications')
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): \Illuminate\Contracts\View\View => view('filament.version')
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetPanelLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
