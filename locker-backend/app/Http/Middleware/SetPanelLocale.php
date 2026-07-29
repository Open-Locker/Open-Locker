<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetPanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales', ['en']);
        $locale = $request->session()->get('locale') ?? $request->cookie('locale');

        if (! is_string($locale) || ! in_array($locale, $supported, true)) {
            // No stored choice yet: negotiate from the browser's Accept-Language,
            // falling back to the first supported locale.
            $locale = $request->getPreferredLanguage($supported);
        }

        if (is_string($locale)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
