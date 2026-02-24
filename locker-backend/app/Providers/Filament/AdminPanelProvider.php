<?php

namespace App\Providers\Filament;

use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (Schema::hasTable('users') && User::count() === 0) {
            $panel->registration();
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->emailVerification()
            ->passwordReset()
            ->profile()
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
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth(300)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.realtime-compartment-open-notifications')
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
