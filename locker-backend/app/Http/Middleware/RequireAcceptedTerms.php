<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TermsService;
use Closure;
use Illuminate\Http\JsonResponse;
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

    public function __construct(private readonly TermsService $termsService) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Keep browsing/read-only API access available until terms are accepted.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if (is_string($routeName) && in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => __('Unauthenticated')], 401);
        }

        $activeVersion = $this->termsService->activeVersion();

        // If no active terms exist yet, do not block domain access.
        if (! $activeVersion) {
            return $next($request);
        }

        if ($this->termsService->hasAcceptedActiveVersion($user)) {
            return $next($request);
        }

        return self::notAcceptedResponse($activeVersion->version);
    }

    /**
     * The refusal the mobile app reads to trigger re-acceptance: it keys off
     * `code`, so the body must not drift. The compartment-open route builds it
     * from here too, because that route records the refusal as an auditable
     * event first and therefore answers after this middleware would have.
     */
    public static function notAcceptedResponse(int|string|null $currentVersion): JsonResponse
    {
        return response()->json([
            'message' => __('You must accept the latest terms before continuing.'),
            'code' => 'terms_not_accepted',
            'terms_current_version' => $currentVersion,
        ], 403);
    }
}
