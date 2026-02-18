<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\UserResource;
use App\Models\CompartmentAccess;
use App\Models\Item;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    /**
     * List of alle users
     *
     * @response AnonymousResourceCollection<UserResource>
     */
    public function getAllUsers(): AnonymousResourceCollection
    {
        return UserResource::collection(User::all());
    }

    /**
     * Macht einen Benutzer zum Administrator
     */
    public function makeAdmin(Request $request, User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return (new ApiErrorResource([
                'message' => __('User is already an administrator.'),
            ]))->response()->setStatusCode(400);
        }

        $user->makeAdmin();

        return response()->json([
            'message' => __('User has been successfully appointed as administrator.'),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Removes administrator rights from a user
     */
    public function removeAdmin(Request $request, User $user): JsonResponse
    {
        if (! $user->isAdmin()) {
            return (new ApiErrorResource([
                'message' => __('User is not an administrator.'),
            ]))->response()->setStatusCode(400);
        }

        // Prevent an admin from removing their own rights
        if (Auth::id() === $user->id) {
            return (new ApiErrorResource([
                'message' => __('You cannot remove your own administrator rights.'),
            ]))->response()->setStatusCode(400);
        }

        $user->removeAdmin();

        return response()->json([
            'message' => __('Administrator rights have been successfully removed.'),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Returns statistics about the system
     */
    public function getStatistics(): JsonResponse
    {
        $totalUsers = User::count();
        $totalItems = Item::count();
        $totalCompartmentAccesses = CompartmentAccess::count();
        $activeCompartmentAccesses = CompartmentAccess::active()->count();

        return response()->json([
            'statistics' => [
                /** @var int $totalUsers Total number of users */
                'total_users' => $totalUsers,
                /** @var int $totalItems Total number of items */
                'total_items' => $totalItems,
                /** @var int $totalCompartmentAccesses Total number of access grants */
                'total_compartment_accesses' => $totalCompartmentAccesses,
                /** @var int $activeCompartmentAccesses Number of currently active grants */
                'active_compartment_accesses' => $activeCompartmentAccesses,
            ],
        ]);
    }
}
