<?php

namespace App\Services;

use App\Jobs\ResetLockerCoil;
use App\Models\Locker;
use Illuminate\Support\Facades\Log;
use OpenLocker\PhpModbusFfi\Contracts\ModbusClient;

// use ModbusMaster; // We are replacing this

readonly class LockerService
{
    // Injizieren Sie das Interface
    public function __construct(private ModbusClient $modbus) {}

    /**
     * Get the list of locker_ids
     *
     * @return array<Locker>
     */
    public function getLockerList(): array
    {
        return Locker::all()->toArray();
    }

    public function openLocker(Locker $locker): bool
    {

        $this->modbus->setSlave($locker->unit_id);
        $this->modbus->connect();

        $this->modbus->writeSingleCoil($locker->coil_address, 1);

        ResetLockerCoil::dispatch($locker)->delay(now()->addSeconds(1));

        $this->modbus->close();

        return true;

    }

    public function resetLockerCoil(Locker $locker): void
    {
        Log::error('Resetting locker coil');
        Log::error($locker);
        $this->modbus->setSlave($locker->unit_id);
        $this->modbus->connect();
        $this->modbus->writeSingleCoil($locker->coil_address, 0);
        $this->modbus->close();
    }

    /**
     * @throws \Exception
     */
    public function getLockerStatus(Locker $locker): bool
    {
        $this->modbus->setSlave($locker->unit_id);
        $this->modbus->connect();
        $result = $this->modbus->readDiscreteInputs($locker->input_address, 1);

        if (count($result) === 0) {
            throw new \Exception('No result');
        }

        return $result[$locker->input_address];
    }
}
