<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

#[SchemaName('CurrentTerms')]
class TermsCurrentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_name' => $this->resource->document_name,
            'version' => $this->resource->version,
            'content' => $this->resource->content,
            'published_at' => $this->resource->published_at,
            'current_accepted' => $this->resource->current_accepted,
        ];
    }
}
