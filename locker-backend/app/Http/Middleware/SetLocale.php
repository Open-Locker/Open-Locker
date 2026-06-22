<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale from the client-supplied `Accept-Language`
 * header, validated against the application's supported locales (ADR-0024).
 *
 * Requests without a usable header keep the default `app.locale`.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('app.supported_locales', ['en']);

        $header = $request->header('Accept-Language');
        if (is_string($header) && $header !== '') {
            // getPreferredLanguage parses quality values and language ranges
            // (e.g. "de-DE" -> "de") and only ever returns a supported locale.
            $preferred = $request->getPreferredLanguage($supported);
            if (is_string($preferred) && in_array($preferred, $supported, true)) {
                App::setLocale($preferred);
            }
        }

        return $next($request);
    }
}
