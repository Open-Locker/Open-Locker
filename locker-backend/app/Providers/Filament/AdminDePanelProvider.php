<?php

namespace App\Providers\Filament;

use Filament\Panel;

class AdminDePanelProvider extends AdminPanelProvider
{
    protected string $locale = 'de';

    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel(
            $panel->id('admin-de')->path('de/admin'),
        );
    }
}
