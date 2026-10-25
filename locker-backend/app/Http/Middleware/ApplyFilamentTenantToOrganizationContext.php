<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;
use App\Support\Organizations\PlatformAdminEntryRecorder;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament owns the panel's tenant switcher; the organization context owns
 * everything else. This hands one to the other so there is still exactly one
 * answer to "which organization am I acting in" — the panel does not get its
 * own parallel notion of it.
 */
class ApplyFilamentTenantToOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Organization) {
            app(OrganizationContext::class)->set($tenant);

            $user = $request->user();

            if ($user instanceof User) {
                app(PlatformAdminEntryRecorder::class)->recordIfEntering($user, $tenant);
            }
        }

        return $next($request);
    }
}
