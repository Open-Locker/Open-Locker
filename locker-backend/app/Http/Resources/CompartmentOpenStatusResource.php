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
            // `opened` now means the door was observed open, not that the unlock
            // pulse was sent — that is `acknowledged`.
            'state' => $this->resource->status->value,
            'compartment_id' => $this->resource->compartment_id,
            'authorization_type' => $this->resource->authorization_type,
            'error_code' => $this->resource->error_code,
            'error_message' => $this->resource->error_message,
            'denied_reason' => $this->resource->denied_reason,
            'requested_at' => $this->resource->requested_at,
            'accepted_at' => $this->resource->accepted_at,
            'denied_at' => $this->resource->denied_at,
            'sent_at' => $this->resource->sent_at,
            'acknowledged_at' => $this->resource->acknowledged_at,
            'opened_at' => $this->resource->opened_at,
            'open_detection_ms' => $this->resource->open_detection_ms,
            'failed_at' => $this->resource->failed_at,
        ];
    }
}
