<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompartmentOpenRequest extends Model
{
    use HasFactory;

    protected $primaryKey = 'command_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'command_id',
        'actor_user_id',
        'compartment_id',
        'authorization_type',
        'status',
        'denied_reason',
        'error_code',
        'error_message',
        'requested_at',
        'accepted_at',
        'denied_at',
        'sent_at',
        'opened_at',
        'failed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'denied_at' => 'datetime',
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, CompartmentOpenRequest>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Compartment, CompartmentOpenRequest>
     */
    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class, 'compartment_id');
    }
}
