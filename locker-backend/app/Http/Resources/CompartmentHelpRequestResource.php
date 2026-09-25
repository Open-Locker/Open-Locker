<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{help_request_id: string} $resource
 */
#[SchemaName('CompartmentHelpRequest')]
class CompartmentHelpRequestResource extends JsonResource
{
    /**
     * @return array{status: bool, help_request_id: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'help_request_id' => $this->resource['help_request_id'],
        ];
    }

    /**
     * Accepted, not done: the managers are notified asynchronously.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(202);
    }
}
