<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $location_description
 * @property-read string $provisioning_token
 * @property-read \Illuminate\Support\CarbonImmutable|null $provisioned_at
 * @property-read \Illuminate\Support\CarbonImmutable|null $last_heartbeat_at
 * @property-read int $heartbeat_interval_seconds
 * @property-read int $heartbeat_timeout_seconds
 * @property-read string $connection_status
 * @property-read \Illuminate\Support\CarbonImmutable|null $connection_status_changed_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Compartment> $compartments
 * @property-read int|null $compartments_count
 */
class LockerBank extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'location_description',
        'provisioning_token',
        'provisioned_at',
        'last_heartbeat_at',
        'heartbeat_interval_seconds',
        'heartbeat_timeout_seconds',
        'connection_status',
        'connection_status_changed_at',
        'last_config_sent_at',
        'last_config_sent_hash',
        'last_config_ack_at',
        'last_config_ack_hash',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'connection_status_changed_at' => 'datetime',
        'last_config_sent_at' => 'datetime',
        'last_config_ack_at' => 'datetime',
    ];

    public static function booted(): void
    {
        static::creating(function (self $lockerBank) {
            $lockerBank->provisioning_token = Str::random(64);
        });
    }

    public function compartments(): HasMany
    {
        return $this->hasMany(Compartment::class);
    }

    /**
     * @return HasManyThrough<CompartmentOpenRequest, Compartment, LockerBank>
     */
    public function openRequests(): HasManyThrough
    {
        return $this->hasManyThrough(
            CompartmentOpenRequest::class,
            Compartment::class,
            'locker_bank_id',
            'compartment_id',
            'id',
            'id'
        );
    }

    /**
     * Build the config payload that will be sent to the client.
     *
     * @return array{config_hash:string, heartbeat_interval_seconds:int, compartments:array<int, array{id:int, slaveId:int, address:int}>}
     */
    public function buildApplyConfigPayload(): array
    {
        $compartments = $this->compartments()
            ->orderBy('number')
            ->get(['number', 'slave_id', 'address'])
            ->map(static function (Compartment $compartment): array {
                return [
                    'id' => (int) $compartment->number,
                    'slaveId' => (int) $compartment->slave_id,
                    'address' => (int) $compartment->address,
                ];
            })
            ->all();

        $json = json_encode($compartments, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);

        return [
            'config_hash' => $hash,
            'heartbeat_interval_seconds' => (int) $this->heartbeat_interval_seconds,
            'compartments' => $compartments,
        ];
    }

    public function currentConfigHash(): string
    {
        return $this->buildApplyConfigPayload()['config_hash'];
    }

    public function isConfigDirty(): bool
    {
        $ackHash = (string) ($this->last_config_ack_hash ?? '');
        if ($ackHash === '') {
            return true;
        }

        return ! hash_equals($ackHash, $this->currentConfigHash());
    }
}
