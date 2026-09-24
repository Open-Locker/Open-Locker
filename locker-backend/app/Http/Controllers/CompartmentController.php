<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureVerifiedEmailApi;
use App\Http\Middleware\RequireAcceptedTerms;
use App\Http\Requests\RequestCompartmentHelpRequest;
use App\Http\Requests\UpdateCompartmentContentNoteRequest;
use App\Http\Resources\AccessibleCompartmentsResource;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\CompartmentContentNoteResource;
use App\Http\Resources\CompartmentHelpRequestResource;
use App\Http\Resources\CompartmentOpenDecisionResource;
use App\Http\Resources\CompartmentOpenStatusResource;
use App\Models\Compartment;
use App\Models\CompartmentOpenRequest;
use App\Services\CompartmentAccessService;
use App\Services\CompartmentService;
use App\Services\TermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompartmentController extends Controller
{
    /**
     * Return compartments accessible by the current user, grouped by locker bank.
     *
     * @response AccessibleCompartmentsResource
     */
    public function accessible(Request $request, CompartmentAccessService $compartmentAccessService): JsonResponse
    {
        $lockerBanks = $compartmentAccessService->accessibleLockerBanksFor($this->authenticatedUser($request));

        return (new AccessibleCompartmentsResource($lockerBanks))->response();
    }

    /**
     * Open a compartment for an authorized user.
     *
     * Dispatches an event-sourced open command and returns immediately.
     *
     * Realtime note:
     * After this endpoint returns, clients should subscribe to
     * `private-users.{userId}.compartment-status` and listen for
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
     * @response 403 {
     *   "message": "You must accept the latest terms before continuing.",
     *   "code": "terms_not_accepted",
     *   "terms_current_version": 2
     * }
     * @response 403 {
     *   "status": false,
     *   "message": "Please verify your email address before opening compartments"
     * }
     */
    public function open(
        Request $request,
        Compartment $compartment,
        CompartmentAccessService $compartmentAccessService,
        TermsService $termsService,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);

        // The API is the one caller that gates on terms acceptance: it is the
        // surface the mobile app talks to, and the only one that can offer the
        // user a way to accept.
        $decision = $compartmentAccessService->requestOpen($user, $compartment, requireAcceptedTerms: true);

        if (! $decision['authorized']) {
            return $this->deniedOpenResponse($decision, $termsService);
        }

        return (new CompartmentOpenDecisionResource([
            'status' => true,
            'command_id' => $decision['command_id'],
            'state' => 'pending',
            'message' => __('Compartment open request accepted'),
        ]))->response()->setStatusCode(202);
    }

    /**
     * Answer a refused open with the body that matches the reason it was refused for.
     *
     * Outstanding terms and an unverified address are checked inside the open
     * path rather than by middleware, so that the attempt is recorded as an
     * auditable event before it is turned away. The bodies are produced by the
     * middleware classes that still guard every other route, so a client sees
     * the same refusal whichever gate it hits — the mobile app drives its
     * re-acceptance flow off the terms body's `code` field.
     *
     * @param  array{authorized: bool, command_id: string, reason: string|null}  $decision
     */
    private function deniedOpenResponse(array $decision, TermsService $termsService): JsonResponse
    {
        return match ($decision['reason']) {
            'terms_not_accepted' => RequireAcceptedTerms::notAcceptedResponse($termsService->activeVersion()?->version),
            'unverified_email' => EnsureVerifiedEmailApi::unverifiedResponse(),
            default => (new CompartmentOpenDecisionResource([
                'status' => false,
                'command_id' => $decision['command_id'],
                'state' => 'denied',
                'message' => __('You do not have access to this compartment'),
            ]))->response()->setStatusCode(403),
        };
    }

    /**
     * Update the free-text content note for a compartment.
     *
     * Any user with active access (direct or via a group) — or an admin — may set
     * the note describing what is stored inside. Send an empty/blank `note` to
     * clear it. The change is event-sourced and auditable (who/when).
     *
     * @response CompartmentContentNoteResource
     */
    public function updateContentNote(
        UpdateCompartmentContentNoteRequest $request,
        Compartment $compartment,
        CompartmentService $compartmentService,
    ): JsonResponse {
        $compartment = $compartmentService->updateContentNote(
            $this->authenticatedUser($request),
            $compartment,
            $request->validated('note'),
        );

        return (new CompartmentContentNoteResource($compartment))->response();
    }

    /**
     * Ask the locker managers for help with a compartment.
     *
     * Any user with active access (direct or via a group) — or an admin — may
     * send a message. Managers get it as a panel alert and an email they can
     * reply to. The request is event-sourced and auditable.
     *
     * @status 202
     */
    public function requestHelp(
        RequestCompartmentHelpRequest $request,
        Compartment $compartment,
        CompartmentService $compartmentService,
    ): CompartmentHelpRequestResource {
        $helpRequestId = $compartmentService->requestHelp(
            $this->authenticatedUser($request),
            $compartment,
            $request->validated('message'),
            $request->validated('phone'),
        );

        return new CompartmentHelpRequestResource(['help_request_id' => $helpRequestId]);
    }

    /**
     * Return status information for a previously created open command.
     *
     * Realtime note:
     * This endpoint is the polling fallback when websocket/reverb push is
     * unavailable. Realtime push uses channel
     * `private-users.{userId}.compartment-status` and event
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

        $user = $this->authenticatedUser($request);
        if (! $user->isAdmin() && $openRequest->actor_user_id !== $user->id) {
            return (new ApiErrorResource([
                'status' => false,
                'message' => __('You are not allowed to view this command'),
            ]))->response()->setStatusCode(403);
        }

        return (new CompartmentOpenStatusResource($openRequest))->response();
    }
}
