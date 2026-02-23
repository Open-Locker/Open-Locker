<?php

namespace App\Http\Resources;

use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
#[SchemaName('User')]
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     email_verified_at: \Illuminate\Support\Carbon|null,
     *     is_admin: bool,
     *     terms_last_accepted_version: int|null,
     *     terms_current_version: int|null,
     *     terms_current_accepted: bool,
     *     created_at: \Illuminate\Support\Carbon|null,
     *     updated_at: \Illuminate\Support\Carbon|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'email_verified_at' => $this->resource->email_verified_at,
            'is_admin' => $this->resource->isAdmin(),
            'terms_last_accepted_version' => $this->resource->latestAcceptedTermsVersion(),
            'terms_current_version' => $this->resource->currentTermsVersion(),
            'terms_current_accepted' => $this->resource->hasAcceptedCurrentTerms(),
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
