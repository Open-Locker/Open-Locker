<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FirmwareUpdateFailureReason;
use App\Enums\FirmwareUpdateStatus;
use App\Enums\LockerAdapterType;
use App\Enums\LockerFeedbackType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $location_description
 * @property-read string|null $support_phone
 * @property LockerAdapterType $adapter_type
 * @property LockerFeedbackType $feedback_type
 * @property string|null $provisioning_token_hmac
 * @property string|null $provisioning_generation
 * @property-read CarbonImmutable|null $provisioning_token_issued_at
 * @property-read CarbonImmutable|null $provisioned_at
 * @property-read CarbonImmutable|null $last_heartbeat_at
 * @property-read int $heartbeat_interval_seconds
 * @property-read int $heartbeat_timeout_seconds
 * @property-read string $connection_status
 * @property-read CarbonImmutable|null $connection_status_changed_at
 * @property-read CarbonImmutable|null $last_compartment_state_change_at
 * @property-read bool $firmware_update_capable
 * @property-read string|null $firmware_version
 * @property-read bool|null $firmware_trial
 * @property-read string|null $firmware_running_slot
 * @property-read string|null $firmware_last_rollback_version
 * @property-read CarbonImmutable|null $firmware_reported_at
 * @property-read FirmwareUpdateStatus|null $firmware_update_status
 * @property-read string|null $firmware_update_release_id
 * @property-read string|null $firmware_update_transaction_id
 * @property-read string|null $firmware_update_target_version
 * @property-read string|null $firmware_update_error_code
 * @property-read FirmwareUpdateFailureReason|null $firmware_update_failure_reason
 * @property-read CarbonImmutable|null $firmware_update_requested_at
 * @property-read Collection<int, Compartment> $compartments
 * @property-read int|null $compartments_count
 */
class LockerBank extends Model
{
    /** Highest zero-based channel address encodable on the RS485 wire (maps to byte 0xFF). */
    public const MAX_WIRE_CHANNEL_ADDRESS = 254;

    /** @use HasFactory<\Database\Factories\LockerBankFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'location_description',
        'support_phone',
        'adapter_type',
        'feedback_type',
        'provisioning_token_hmac',
        'provisioning_generation',
        'provisioning_token_issued_at',
        'provisioned_at',
        'last_heartbeat_at',
        'heartbeat_interval_seconds',
        'heartbeat_timeout_seconds',
        'connection_status',
        'connection_status_changed_at',
        'modbus_connected',
        'modbus_status_reported_at',
        'last_compartment_state_change_at',
        'last_config_sent_at',
        'last_config_sent_hash',
        'last_config_ack_at',
        'last_config_ack_hash',
        'firmware_update_capable',
        'firmware_update_status',
        'firmware_update_transaction_id',
        'firmware_update_release_id',
        'firmware_update_target_version',
        'firmware_update_error_code',
        'firmware_update_failure_reason',
        'firmware_update_requested_at',
        'firmware_version',
        'firmware_trial',
        'firmware_running_slot',
        'firmware_last_rollback_version',
        'firmware_reported_at',
    ];

    protected $casts = [
        'adapter_type' => LockerAdapterType::class,
        'feedback_type' => LockerFeedbackType::class,
        'provisioning_token_issued_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'connection_status_changed_at' => 'datetime',
        'modbus_connected' => 'boolean',
        'modbus_status_reported_at' => 'datetime',
        'last_compartment_state_change_at' => 'datetime',
        'last_config_sent_at' => 'datetime',
        'last_config_ack_at' => 'datetime',
        'firmware_update_capable' => 'boolean',
        'firmware_update_status' => FirmwareUpdateStatus::class,
        'firmware_update_failure_reason' => FirmwareUpdateFailureReason::class,
        'firmware_update_requested_at' => 'datetime',
        'firmware_trial' => 'boolean',
        'firmware_reported_at' => 'datetime',
    ];

    /**
     * The transaction of the firmware update this bank is busy with, or null when none is in flight.
     */
    public function inFlightTransactionId(): ?string
    {
        return $this->firmware_update_status?->isInFlight() === true
            ? $this->firmware_update_transaction_id
            : null;
    }

    /**
     * Whether this bank is in the middle of an update to exactly this release: the one test that
     * lets it fetch the image.
     */
    public function isDownloadingRelease(FirmwareRelease $release): bool
    {
        return $this->firmware_update_status?->isInFlight() === true
            && $this->firmware_update_release_id === $release->id;
    }

    /**
     * Banks with a firmware update in flight (see {@see FirmwareUpdateStatus::inFlightCases()}).
     *
     * @param  Builder<LockerBank>  $query
     */
    public function scopeUpdateInFlight(Builder $query): void
    {
        $query->whereIn('firmware_update_status', array_map(
            static fn (FirmwareUpdateStatus $status): string => $status->value,
            FirmwareUpdateStatus::inFlightCases(),
        ));
    }

    /**
     * @return HasMany<Compartment, $this>
     */
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
     * @return array{config_hash:string, heartbeat_interval_seconds:int, adapter_type:string, feedback_type:string, compartments:array<int, array{compartment_number:int, slaveId:int, address:int}>}
     */
    public function buildApplyConfigPayload(): array
    {
        $compartments = $this->compartments()
            ->orderBy('number')
            ->get(['number', 'slave_id', 'address'])
            ->map(static function (Compartment $compartment): array {
                return [
                    'compartment_number' => (int) $compartment->number,
                    'slaveId' => (int) $compartment->slave_id,
                    'address' => (int) $compartment->address,
                ];
            })
            ->all();

        $canonicalConfig = [
            'adapter_type' => $this->adapter_type->value,
            'feedback_type' => $this->feedback_type->value,
            'compartments' => $compartments,
        ];
        $json = json_encode($canonicalConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);

        return [
            'config_hash' => $hash,
            'heartbeat_interval_seconds' => (int) $this->heartbeat_interval_seconds,
            ...$canonicalConfig,
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
