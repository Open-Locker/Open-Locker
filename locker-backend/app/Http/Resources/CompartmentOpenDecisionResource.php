<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('CompartmentOpenDecision')]
class CompartmentOpenDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => (bool) ($this['status'] ?? false),
            'command_id' => (string) ($this['command_id'] ?? ''),
            'state' => (string) ($this['state'] ?? 'denied'),
            'message' => (string) ($this['message'] ?? ''),
        ];
    }
}
