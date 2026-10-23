<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confines a model to the organization currently being acted in.
 *
 * The scope is what we write; the database constraints are what save us when
 * someone forgets it. Both exist deliberately — a query that escapes the scope
 * still cannot write across the boundary.
 *
 * With no organization in context the scope matches nothing rather than
 * everything: work that never said which organization it meant gets no rows,
 * which fails loudly instead of leaking quietly.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $context = app(OrganizationContext::class);

            if (! $context->has()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->getModel()->getTable().'.organization_id', $context->currentId());
        });

        static::creating(function ($model): void {
            $model->organization_id ??= app(OrganizationContext::class)->currentId();
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
