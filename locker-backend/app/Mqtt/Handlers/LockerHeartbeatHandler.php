<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Models\LockerBank;
use App\Mqtt\InboundMqttProtocolGuard;
use App\Services\FirmwareUpdateOutcomeService;
use App\StorableEvents\LockerConnectionRestored;
use App\Support\EventSourcing\StoredEventDispatcher;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Handles AsyncAPI heartbeat payloads on locker/{uuid}/state/heartbeat (retain=false).
 */
class LockerHeartbeatHandler extends AbstractInboundMqttHandler
{
    public function __construct(
        InboundMqttProtocolGuard $guard,
        private readonly StoredEventDispatcher $storedEventDispatcher,
        private readonly FirmwareUpdateOutcomeService $firmwareUpdateOutcome,
    ) {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/state/heartbeat';
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT heartbeat received';
    }

    /**
     * Heartbeats arrive on a timer from every locker forever, so tracing them
     * would bury the flows worth looking at.
     */
    protected function tracesInboundMessages(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'message_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'uptime_seconds' => ['required', 'integer', 'min:0'],
            // Optional: older clients heartbeat without reporting hardware state.
            'modbus_connected' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The firmware report (ADR-0076) is telemetry, so it is validated apart from the heartbeat: a
     * malformed report must not make the locker miss heartbeats and look offline. `trial` is required
     * with the version, and must be a real boolean, so a report that does not say outright whether the
     * image is on trial never counts.
     *
     * @return array<string, mixed>
     */
    private function firmwareRules(): array
    {
        return [
            'firmware_version' => ['sometimes', 'string', 'min:1', 'max:31'],
            'trial' => [
                'required_with:firmware_version',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('The :attribute must be true or false.');
                    }
                },
            ],
            'running_slot' => ['sometimes', 'string', 'in:ota_0,ota_1'],
            'last_rollback_version' => ['sometimes', 'string', 'min:1', 'max:31'],
            'capabilities' => ['sometimes', 'array', 'list'],
            'capabilities.*' => ['string', 'min:1'],
        ];
    }

    /**
     * The firmware fields of a heartbeat when they are all well formed; null when there are none or
     * any is malformed, in which case the whole firmware part is ignored and a warning (field names
     * only, never the payload) says so.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function firmwareReport(array $payload, string $lockerBankUuid): ?array
    {
        $fields = Arr::only($payload, ['firmware_version', 'trial', 'running_slot', 'last_rollback_version', 'capabilities']);
        if ($fields === []) {
            return null;
        }

        $validator = Validator::make($fields, $this->firmwareRules());
        if ($validator->fails()) {
            Log::warning('Firmware report of a heartbeat ignored: malformed fields.', [
                'uuid' => $lockerBankUuid,
                'fields' => array_keys($validator->errors()->toArray()),
            ]);

            return null;
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = $this->lockerBankUuidFromTopic($topic);

        $timestamp = isset($payload['timestamp']) && is_string($payload['timestamp'])
            ? $payload['timestamp']
            : null;

        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('Heartbeat received for unknown locker bank', [
                'uuid' => $lockerBankUuid,
                'timestamp' => $timestamp,
            ]);

            return;
        }

        $ts = $timestamp ? Carbon::parse($timestamp) : now();
        // Anything that is not already online becomes online on a heartbeat. A
        // freshly provisioned bank starts at 'unknown', and checking only for
        // 'offline' left it there forever: it had never timed out, so nothing
        // ever recorded that it was up.
        $wasNotOnline = $lockerBank->connection_status !== 'online';

        $attributes = ['last_heartbeat_at' => $ts];

        // Absent means the device reported nothing about its bus, which must not
        // be recorded as the bus being down. The previous value is left alone.
        if (array_key_exists('modbus_connected', $payload)) {
            $attributes['modbus_connected'] = (bool) $payload['modbus_connected'];
            $attributes['modbus_status_reported_at'] = $ts;
        }

        // Firmware: absent means the device reports none (the Pi client, firmware from before updates),
        // and the stored values are left alone. A report replaces all of it, so a rollback that is no
        // longer remembered is not kept.
        $firmware = $this->firmwareReport($payload, $lockerBankUuid);
        if ($firmware !== null && array_key_exists('firmware_version', $firmware)) {
            $attributes['firmware_version'] = (string) $firmware['firmware_version'];
            $attributes['firmware_trial'] = (bool) $firmware['trial'];
            $attributes['firmware_running_slot'] = isset($firmware['running_slot']) ? (string) $firmware['running_slot'] : null;
            $attributes['firmware_last_rollback_version'] = isset($firmware['last_rollback_version']) ? (string) $firmware['last_rollback_version'] : null;
            $attributes['firmware_reported_at'] = $ts;
        }
        if ($firmware !== null && array_key_exists('capabilities', $firmware)) {
            $attributes['firmware_update_capable'] = in_array('firmware_update', $firmware['capabilities'], true);
        }

        $lockerBank->forceFill($attributes)->save();

        Log::info('Heartbeat received', [
            'uuid' => $lockerBankUuid,
            'timestamp' => $timestamp,
        ]);

        if ($firmware !== null && array_key_exists('firmware_version', $firmware)) {
            $this->firmwareUpdateOutcome->derive($lockerBank, [
                'firmware_version' => (string) $firmware['firmware_version'],
                'trial' => (bool) $firmware['trial'],
                'last_rollback_version' => isset($firmware['last_rollback_version']) ? (string) $firmware['last_rollback_version'] : null,
            ], $ts);
        }

        if ($wasNotOnline) {
            $this->storedEventDispatcher->dispatch(new LockerConnectionRestored(
                lockerBankUuid: $lockerBankUuid,
                restoredAtIso8601: $ts->toIso8601String(),
                reason: 'heartbeat',
            ));
        }
    }

    private function lockerBankUuidFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);

        return $parts[1] ?? '';
    }
}
