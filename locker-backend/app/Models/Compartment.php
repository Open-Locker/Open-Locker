<?php

namespace App\Models;

use App\Enums\CompartmentDoorState;
use Database\Factories\CompartmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

/**
 * @property int|null $number
 * @property string|null $locker_bank_id
 * @property int|null $slave_id
 * @property int|null $address
 * @property CompartmentDoorState $door_state
 * @property \Illuminate\Support\Carbon|null $door_state_changed_at
 * @property \Illuminate\Support\Carbon|null $last_opened_at
 * @property \Illuminate\Support\Carbon|null $last_open_failed_at
 * @property string|null $content_note
 * @property \Illuminate\Support\Carbon|null $content_note_updated_at
 * @property int|null $content_note_updated_by_user_id
 */
class Compartment extends Model
{
    /** @use HasFactory<CompartmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'locker_bank_id',
        'number',
        'slave_id',
        'address',
        'door_state',
        'door_state_changed_at',
        'last_opened_at',
        'last_open_failed_at',
        'last_open_transaction_id',
        'last_open_error_code',
        'last_open_error_message',
        'content_note',
        'content_note_updated_at',
        'content_note_updated_by_user_id',
    ];

    public static function booted(): void
    {
        static::creating(function (self $compartment) {
            if (is_null($compartment->number)) {
                $maxNumber = self::where('locker_bank_id', $compartment->locker_bank_id)->max('number');
                $compartment->number = $maxNumber + 1;
            }
        });

        static::saving(function (self $compartment) {
            if (is_null($compartment->locker_bank_id) || is_null($compartment->slave_id) || is_null($compartment->address)) {
                return;
            }

            $query = self::query()
                ->where('locker_bank_id', $compartment->locker_bank_id)
                ->where('slave_id', $compartment->slave_id)
                ->where('address', $compartment->address);

            if ($compartment->exists) {
                $query->where('id', '!=', $compartment->id);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'address' => 'This Slave ID + Address combination is already used in this locker bank.',
                ]);
            }
        });
    }

    /**
     * @return HasMany<CompartmentOpenRequest, Compartment>
     */
    public function openRequests(): HasMany
    {
        return $this->hasMany(CompartmentOpenRequest::class, 'compartment_id', 'id');
    }

    /**
     * @return HasOne<CompartmentOpenRequest, Compartment>
     */
    public function latestOpenRequest(): HasOne
    {
        return $this->hasOne(CompartmentOpenRequest::class, 'compartment_id', 'id')->latestOfMany('requested_at');
    }

    /**
     * @return BelongsTo<LockerBank, $this>
     */
    public function lockerBank(): BelongsTo
    {
        return $this->belongsTo(LockerBank::class);
    }

    /**
     * @return HasMany<CompartmentAccess, Compartment>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(CompartmentAccess::class);
    }

    /**
     * Group-level access grants for this compartment (managed via GroupAccessService).
     *
     * @return HasMany<GroupCompartmentAccess, Compartment>
     */
    public function groupAccesses(): HasMany
    {
        return $this->hasMany(GroupCompartmentAccess::class);
    }

    /**
     * Derived effective access via groups (read model maintained by GroupProjector).
     *
     * @return HasMany<UserGroupCompartmentAccess, Compartment>
     */
    public function userGroupAccesses(): HasMany
    {
        return $this->hasMany(UserGroupCompartmentAccess::class);
    }

    /**
     * @return HasMany<CompartmentAccess, Compartment>
     */
    public function activeAccesses(): HasMany
    {
        return $this->accesses()
            ->whereNull('revoked_at')
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'slave_id' => 'integer',
            'address' => 'integer',
            'door_state' => CompartmentDoorState::class,
            'door_state_changed_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'last_open_failed_at' => 'datetime',
            'content_note_updated_at' => 'datetime',
            'content_note_updated_by_user_id' => 'integer',
        ];
    }
}
