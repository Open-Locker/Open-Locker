<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Compartment;
use App\Models\CompartmentOpenRequest;
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
     * @response 202 {
     *   "status": true,
     *   "command_id": "8deed4ea-798b-4f95-b335-bcc7fab88a06",
     *   "state": "pending",
     *   "message": "Compartment open request accepted"
     * }
     * @response 403 {
     *   "status": false,
     *   "command_id": "8deed4ea-798b-4f95-b335-bcc7fab88a06",
     *   "state": "denied",
     *   "message": "You do not have access to this compartment"
     * }
     */
    public function open(
        Request $request,
        Compartment $compartment,
        CompartmentAccessService $compartmentAccessService,
    ): JsonResponse {
        $decision = $compartmentAccessService->requestOpen($request->user(), $compartment);

        if (! $decision['authorized']) {
            return response()->json([
                'status' => false,
                'command_id' => $decision['command_id'],
                'state' => 'denied',
                'message' => __('You do not have access to this compartment'),
            ], 403);
        }

        return response()->json([
            'status' => true,
            'command_id' => $decision['command_id'],
            'state' => 'pending',
            'message' => __('Compartment open request accepted'),
        ], 202);
    }

    /**
     * Return status information for a previously created open command.
     */
    public function openStatus(Request $request, string $commandId): JsonResponse
    {
        $openRequest = CompartmentOpenRequest::query()->find($commandId);
        if (! $openRequest) {
            return response()->json([
                'status' => false,
                'message' => __('Command not found'),
            ], 404);
        }

        $user = $request->user();
        if (! $user->isAdmin() && $openRequest->actor_user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => __('You are not allowed to view this command'),
            ], 403);
        }

        return response()->json([
            'status' => true,
            'command_id' => $openRequest->command_id,
            'state' => $openRequest->status,
            'compartment_id' => $openRequest->compartment_id,
            'authorization_type' => $openRequest->authorization_type,
            'error_code' => $openRequest->error_code,
            'error_message' => $openRequest->error_message,
            'denied_reason' => $openRequest->denied_reason,
            'requested_at' => $openRequest->requested_at,
            'accepted_at' => $openRequest->accepted_at,
            'denied_at' => $openRequest->denied_at,
            'sent_at' => $openRequest->sent_at,
            'opened_at' => $openRequest->opened_at,
            'failed_at' => $openRequest->failed_at,
        ]);
    }
}
