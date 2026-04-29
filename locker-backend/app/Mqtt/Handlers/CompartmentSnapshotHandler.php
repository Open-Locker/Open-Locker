<?php

declare(strict_types=1);

namespace App\Mqtt\Handlers;

use App\Enums\CompartmentDoorState;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Mqtt\InboundMqttProtocolGuard;
use App\StorableEvents\CompartmentDoorStateChanged;
use App\StorableEvents\CompartmentStateChangesApplied;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Validates MQTT compartment snapshots; persists facts via stored events and projections (see ADR-0016).
 */
class CompartmentSnapshotHandler extends AbstractInboundMqttHandler
{
    public function __construct(InboundMqttProtocolGuard $guard)
    {
        parent::__construct($guard);
    }

    public function topicPattern(): string
    {
        return 'locker/+/state/compartments';
    }

    protected function blocksInboundDuplicateMessageIds(): bool
    {
        return false;
    }

    protected function receivedLogMessage(): string
    {
        return 'MQTT compartment snapshot received';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'message_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'compartments' => ['required', 'array'],
            'compartments.*.compartment_number' => ['required', 'integer', 'min:1'],
            'compartments.*.door_state' => ['required', 'string', 'in:open,closed,unknown'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleValidated(string $topic, array $payload): void
    {
        $lockerBankUuid = $this->lockerBankUuidFromTopic($topic);

        /** @var LockerBank|null $lockerBank */
        $lockerBank = LockerBank::find($lockerBankUuid);
        if (! $lockerBank) {
            Log::warning('Compartment snapshot for unknown locker bank', [
                'uuid' => $lockerBankUuid,
            ]);

            return;
        }

        $ts = Carbon::parse((string) $payload['timestamp']);
        $mqttMessageId = (string) $payload['message_id'];
        $compartments = $payload['compartments'];
        if (! is_array($compartments)) {
            return;
        }

        $hadDoorDelta = false;

        foreach ($compartments as $row) {
            if (! is_array($row)) {
                continue;
            }

            $number = $row['compartment_number'] ?? null;
            $doorStateRaw = $row['door_state'] ?? null;
            if (! is_int($number) || ! is_string($doorStateRaw)) {
                continue;
            }

            $doorState = CompartmentDoorState::from($doorStateRaw);

            $compartment = Compartment::query()
                ->where('locker_bank_id', $lockerBank->id)
                ->where('number', $number)
                ->first();

            if (! $compartment) {
                Log::info('Snapshot references unknown compartment number for locker bank', [
                    'locker_bank_id' => $lockerBank->id,
                    'compartment_number' => $number,
                ]);

                continue;
            }

            $previousState = $compartment->door_state;

            if ($previousState === $doorState) {
                continue;
            }

            $hadDoorDelta = true;

            event(new CompartmentDoorStateChanged(
                lockerBankUuid: $lockerBankUuid,
                compartmentUuid: (string) $compartment->id,
                compartmentNumber: $number,
                previousDoorState: $previousState->value,
                newDoorState: $doorState->value,
                doorStateChangedAtIso8601: $ts->toIso8601String(),
                mqttMessageId: $mqttMessageId,
            ));
        }

        if ($hadDoorDelta) {
            event(new CompartmentStateChangesApplied(
                lockerBankUuid: $lockerBankUuid,
                changesObservedAtIso8601: $ts->toIso8601String(),
                mqttMessageId: $mqttMessageId,
            ));
        }
    }

    private function lockerBankUuidFromTopic(string $topic): string
    {
        $parts = explode('/', $topic);

        return $parts[1] ?? '';
    }
}
