<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompartmentAccess extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'granted_by_user_id',
        'revoked_by_user_id',
        'compartment_id',
        'granted_at',
        'expires_at',
        'revoked_at',
        'notes',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, CompartmentAccess>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, CompartmentAccess>
     */
    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    /**
     * @return BelongsTo<User, CompartmentAccess>
     */
    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * @return BelongsTo<Compartment, CompartmentAccess>
     */
    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class);
    }

    /**
     * @param  Builder<CompartmentAccess>  $query
     * @return Builder<CompartmentAccess>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
