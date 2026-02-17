<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Compartment;
use App\Services\CompartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompartmentController extends Controller
{
    /**
     * Open a compartment for an authorized user.
     *
     * Dispatches an event-sourced open command and returns immediately.
     *
     * @response 200 {
     *   "status": true,
     *   "message": "Compartment open command queued"
     * }
     * @response 403 {
     *   "status": false,
     *   "message": "You do not have access to this compartment"
     * }
     */
    public function open(
        Request $request,
        Compartment $compartment,
        CompartmentAccessService $compartmentAccessService,
    ): JsonResponse {
        if (! $compartmentAccessService->requestOpen($request->user(), $compartment)) {
            return response()->json([
                'status' => false,
                'message' => __('You do not have access to this compartment'),
            ], 403);
        }

        return response()->json([
            'status' => true,
            'message' => __('Compartment open command queued'),
        ]);
    }
}
