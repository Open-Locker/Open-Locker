<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AccessibleCompartmentsResource;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\CompartmentOpenDecisionResource;
use App\Http\Resources\CompartmentOpenStatusResource;
use App\Models\Compartment;
use App\Models\CompartmentOpenRequest;
use App\Models\LockerBank;
use App\Services\CompartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompartmentController extends Controller
{
    /**
     * Return compartments accessible by the current user, grouped by locker bank.
     *
     * @response AccessibleCompartmentsResource
     */
    public function accessible(Request $request): JsonResponse
    {
        $user = $request->user();

        $lockerBanksQuery = LockerBank::query()
            ->orderBy('name');

        if ($user->isAdmin()) {
            $lockerBanksQuery->with([
                'compartments' => fn ($query) => $query
                    ->with('item')
                    ->orderBy('number'),
            ]);
        } else {
            $lockerBanksQuery
                ->whereHas('compartments.accesses', function ($query) use ($user): void {
                    $query->where('user_id', $user->id)->active();
                })
                ->with([
                    'compartments' => fn ($query) => $query
                        ->whereHas('accesses', function ($accessQuery) use ($user): void {
                            $accessQuery->where('user_id', $user->id)->active();
                        })
                        ->with('item')
                        ->orderBy('number'),
                ]);
        }

        return (new AccessibleCompartmentsResource($lockerBanksQuery->get()))->response();
    }

    /**
     * Open a compartment for an authorized user.
     *
     * Dispatches an event-sourced open command and returns immediately.
     *
     * Realtime note:
     * After this endpoint returns, clients should subscribe to
     * `private-users.{userId}.compartment-open` and listen for
     * `.compartment.open.status.updated` events.
     * Payload fields: `command_id`, `compartment_id`, `status`,
     * `error_code`, `message`.
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
            return (new CompartmentOpenDecisionResource([
                'status' => false,
                'command_id' => $decision['command_id'],
                'state' => 'denied',
                'message' => __('You do not have access to this compartment'),
            ]))->response()->setStatusCode(403);
        }

        return (new CompartmentOpenDecisionResource([
            'status' => true,
            'command_id' => $decision['command_id'],
            'state' => 'pending',
            'message' => __('Compartment open request accepted'),
        ]))->response()->setStatusCode(202);
    }

    /**
     * Return status information for a previously created open command.
     *
     * Realtime note:
     * This endpoint is the polling fallback when websocket/reverb push is
     * unavailable. Realtime push uses channel
     * `private-users.{userId}.compartment-open` and event
     * `.compartment.open.status.updated`.
     */
    public function openStatus(Request $request, string $commandId): JsonResponse
    {
        $openRequest = CompartmentOpenRequest::query()->find($commandId);
        if (! $openRequest) {
            return (new ApiErrorResource([
                'status' => false,
                'message' => __('Command not found'),
            ]))->response()->setStatusCode(404);
        }

        $user = $request->user();
        if (! $user->isAdmin() && $openRequest->actor_user_id !== $user->id) {
            return (new ApiErrorResource([
                'status' => false,
                'message' => __('You are not allowed to view this command'),
            ]))->response()->setStatusCode(403);
        }

        return (new CompartmentOpenStatusResource($openRequest))->response();
    }
}
