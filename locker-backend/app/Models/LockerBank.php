<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $location_description
 * @property-read string $provisioning_token
 * @property-read \Illuminate\Support\CarbonImmutable|null $provisioned_at
 * @property-read \Illuminate\Support\CarbonImmutable|null $last_heartbeat_at
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
        'last_config_sent_at',
        'last_config_sent_hash',
        'last_config_ack_at',
        'last_config_ack_hash',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
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
     * Build the config payload that will be sent to the client.
     *
     * @return array{config_hash:string, compartments:array<int, array{id:int, slaveId:int, address:int}>}
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
