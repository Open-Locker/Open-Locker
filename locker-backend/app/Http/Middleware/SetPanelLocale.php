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
        $segment = $request->segment(1);

        if (is_string($segment) && in_array($segment, $supported, true)) {
            App::setLocale($segment);
        }

        return $next($request);
    }
}
