<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CompartmentOpenRequest;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CompartmentOpenRequest $resource
 */
#[SchemaName('CompartmentOpenStatus')]
class CompartmentOpenStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => true,
            'command_id' => $this->resource->command_id,
            'state' => $this->resource->status,
            'compartment_id' => $this->resource->compartment_id,
            'authorization_type' => $this->resource->authorization_type,
            'error_code' => $this->resource->error_code,
            'error_message' => $this->resource->error_message,
            'denied_reason' => $this->resource->denied_reason,
            'requested_at' => $this->resource->requested_at,
            'accepted_at' => $this->resource->accepted_at,
            'denied_at' => $this->resource->denied_at,
            'sent_at' => $this->resource->sent_at,
            'opened_at' => $this->resource->opened_at,
            'failed_at' => $this->resource->failed_at,
        ];
    }
}
