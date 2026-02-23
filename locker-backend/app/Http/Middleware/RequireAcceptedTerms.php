<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TermsDocument;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAcceptedTerms
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'auth.logout',
        'auth.user',
        'auth.profile.update',
        'auth.password.update',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => __('Unauthenticated')], 401);
        }

        $document = TermsDocument::query()->with('activeVersion')->oldest('id')->first();
        $activeVersion = $document?->activeVersion;

        // If no active terms exist yet, do not block domain access.
        if (! $document || ! $activeVersion) {
            return $next($request);
        }

        $acceptedCurrentVersion = $user->termsAcceptances()
            ->where('terms_document_id', $document->id)
            ->where('terms_document_version_id', $activeVersion->id)
            ->exists();

        if ($acceptedCurrentVersion) {
            return $next($request);
        }

        return response()->json([
            'message' => __('You must accept the latest terms before continuing.'),
            'code' => 'terms_not_accepted',
            'terms_current_version' => $activeVersion->version,
        ], 403);
    }
}
