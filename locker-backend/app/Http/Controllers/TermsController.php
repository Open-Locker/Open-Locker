<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\TermsCurrentResource;
use App\Models\User;
use App\Services\TermsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsController extends Controller
{
    public function __construct(private readonly TermsService $termsService) {}

    /**
     * Return the currently active terms document version.
     */
    public function current(Request $request): TermsCurrentResource|JsonResponse
    {
        $document = $this->termsService->getCurrentDocument();
        $activeVersion = $document?->activeVersion;
        $user = $request->user();

        if (! $document || ! $activeVersion || ! $user instanceof User) {
            return (new ApiErrorResource([
                'message' => __('No active terms available'),
            ]))->response()->setStatusCode(404);
        }

        $latestAcceptance = $user->termsAcceptances()
            ->with('acceptedVersion')
            ->latest('accepted_at')
            ->first();

        return new TermsCurrentResource((object) [
            'document_name' => $document->name,
            'version' => $activeVersion->version,
            'content' => $activeVersion->content,
            'published_at' => $activeVersion->published_at,
            'current_accepted' => $latestAcceptance?->terms_document_version_id === $activeVersion->id,
        ]);
    }

    /**
     * Accept the currently active terms version.
     */
    public function accept(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'message' => __('Unauthenticated'),
            ], 401);
        }

        try {
            $version = $this->termsService->acceptCurrentTerms($user);
        } catch (ModelNotFoundException) {
            return (new ApiErrorResource([
                'message' => __('No active terms available'),
            ]))->response()->setStatusCode(404);
        }

        return response()->json([
            'message' => __('Terms accepted successfully'),
            'accepted_version' => $version->version,
            'accepted_at' => now()->toIso8601String(),
        ]);
    }
}
