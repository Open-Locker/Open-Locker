<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\OrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * Organizations the current user belongs to.
     *
     * The only endpoint that exists because of multi-organization support: it
     * is what the app's switcher displays. A user with one membership never
     * needs it, because the API resolves that membership on its own.
     *
     * @response array<int, array{id: string, name: string, slug: string}>
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = $this->authenticatedUser($request)
            ->organizations()
            ->orderBy('name')
            ->get();

        return OrganizationResource::collection($organizations)->response();
    }
}
