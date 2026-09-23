<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;
use App\Support\Organizations\PlatformAdminEntryRecorder;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which organization a request is acting in.
 *
 * Switching must never log anyone out, so the organization travels in a header
 * rather than in the token. The client states where it means to act and the
 * server decides what that statement is allowed to mean.
 */
class ResolveOrganization
{
    public const HEADER = 'X-Organization';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $requested = $request->header(self::HEADER);

        if (is_string($requested) && $requested !== '') {
            $organization = $this->memberOrganization($user, $requested);

            if (! $organization instanceof Organization) {
                return self::notAMemberResponse();
            }

            app(OrganizationContext::class)->set($organization);

            // Entering is what makes an operator's data visible, so it is
            // recorded here as well as in the panel. A platform admin reaching
            // an organization over the API is the same act as reaching it
            // through the switcher.
            app(PlatformAdminEntryRecorder::class)->recordIfEntering($user, $organization);

            return $next($request);
        }

        // No header means "wherever I belong", and the server answers with the
        // first of them. Refusing instead would make signing in a decision
        // before the app has shown anything — and there is nothing to protect
        // against: every organization here is one this person already belongs
        // to, so any of them is a legitimate place to start. Choosing a
        // different one is a switch inside the app, not a gate in front of it.
        $organization = $user->organizations()->orderBy('name')->first();

        if ($organization instanceof Organization) {
            app(OrganizationContext::class)->set($organization);
        }

        return $next($request);
    }

    private function memberOrganization(User $user, string $organizationId): ?Organization
    {
        // A header is a statement of intent, never authority: membership is
        // what decides, and a platform admin is trusted to enter any operator.
        if ($user->isPlatformAdmin()) {
            return Organization::query()->find($organizationId);
        }

        return $user->organizations()->whereKey($organizationId)->first();
    }

    public static function notAMemberResponse(): JsonResponse
    {
        return response()->json([
            'message' => __('You do not belong to this organization.'),
            'code' => 'organization_forbidden',
        ], 403);
    }
}
