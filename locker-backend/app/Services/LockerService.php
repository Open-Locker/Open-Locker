<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LockerStatus;
use App\Exceptions\InvalidAddressException;
use App\Models\Locker;
use Exception;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use OpenLocker\PhpModbusFfi\Contracts\ModbusClient;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException;

class LockerService
{
    const MODBUS_LOCK = 'modbus_lock';

    private const DEFAULT_MODBUS_RESPONSE_TIMEOUT_SECONDS = 1.0; // Standard-Fallback-Timeout in Sekunden

    private const LOCK_TIMEOUT_BUFFER_SECONDS = 1.0; // Zusätzlicher Puffer für Lock-TTL

    public function __construct(protected ModbusClient $modbus) {}

    private function getUnitId(): int
    {
        return $this->modbus->getSlave();
    }

    /**
     * Ruft den Modbus-Antworttimeout ab und konvertiert ihn in Sekunden (float).
     *
     * @return float Der Timeout in Sekunden.
     */
    public function getModbusResponseTimeoutInSeconds(): float
    {
        try {
            // Sicherstellen, dass eine Verbindung besteht, bevor der Timeout abgefragt wird,
            // da manche Implementierungen dies erfordern könnten oder um sicherzustellen,
            // dass der Timeout für die aktuelle Verbindungskonfiguration gilt.
            // Wenn getResponseTimeout() keine aktive Verbindung benötigt, kann dieser Block angepasst werden.
            if (! $this->modbus->isCurrentlyConnected()) {
                // Versuche, eine temporäre Verbindung aufzubauen, um den Timeout zu lesen,
                // oder verwende einen Standardwert. Für dieses Beispiel verwenden wir einen Standardwert,
                // wenn keine Verbindung besteht, um die Komplexität gering zu halten.
                // Alternativ: $this->modbus->connect(); gefolgt von $this->modbus->close(); nach dem Auslesen,
                // was aber Overhead erzeugt.
                Log::warning('Modbus client is not connected. Using default response timeout.');

                return self::DEFAULT_MODBUS_RESPONSE_TIMEOUT_SECONDS;
            }

            $timeoutArray = $this->modbus->getResponseTimeout(); // Gibt ['seconds' => int, 'microseconds' => int] zurück

            if (isset($timeoutArray['seconds']) && isset($timeoutArray['microseconds'])) {
                $seconds = (int) $timeoutArray['seconds'];
                $microseconds = (int) $timeoutArray['microseconds'];

                return $seconds + ($microseconds / 1000000.0);
            } else {
                Log::warning('Modbus response timeout array has an unexpected format. Using default.', ['timeout_array' => $timeoutArray]);

                return self::DEFAULT_MODBUS_RESPONSE_TIMEOUT_SECONDS;
            }
        } catch (Exception $e) {
            // Fängt ModbusIOException oder andere Exceptions ab, die von getResponseTimeout() geworfen werden könnten.
            Log::error('Failed to get Modbus response timeout: '.$e->getMessage().'. Using default.');

            return self::DEFAULT_MODBUS_RESPONSE_TIMEOUT_SECONDS;
        }
    }

    /**
     * Führt eine Modbus-Operation unter einem Lock aus,
     * kümmert sich um Verbindungsaufbau und -abbau zur spezifizierten Unit ID.
     *
     * @param  int  $unitId  Die Unit ID, mit der kommuniziert werden soll.
     * @param  callable  $operation  Die auszuführende Operation. Erhält die ModbusClient-Instanz als Parameter.
     * @param  int|null  $lockTtl  Sekunden, für die das Lock maximal gehalten wird. Wenn null, wird es berechnet.
     * @param  int  $blockFor  Sekunden, die maximal auf das Lock gewartet wird.
     * @return mixed Das Ergebnis der Callback-Operation.
     *
     * @throws LockTimeoutException
     * @throws Exception
     */
    protected function executeModbusOperation(int $unitId, callable $operation, ?int $lockTtl = null, int $blockFor = 5): mixed
    {
        if ($lockTtl === null) {
            $modbusTimeout = $this->getModbusResponseTimeoutInSeconds();
            $lockTtl = (int) ceil($modbusTimeout + self::LOCK_TIMEOUT_BUFFER_SECONDS);
        }

        return Cache::lock(self::MODBUS_LOCK, $lockTtl)->block($blockFor, function () use ($unitId, $operation, $lockTtl) {
            Log::info("Attempting to connect to Unit ID: {$unitId} for Modbus operation with lock TTL: {$lockTtl}s.");
            $this->connectToUnit($unitId);

            try {
                $result = $operation($this->modbus);
                Log::info("Modbus operation for Unit ID: {$unitId} completed.");

                return $result;
            } catch (ModbusIOException $e) {
                // Spezifischere Fehlerbehandlung für Modbus-IO-Fehler
                $errorMessage = $e->getMessage();
                Log::error("ModbusIO error during operation for Unit ID {$unitId}: ".$errorMessage);

                // Prüfen auf "Illegal data address" Fehler
                if (str_contains($errorMessage, 'Illegal data address')) {
                    // Extrahieren der Adresse aus der Fehlermeldung, falls möglich
                    preg_match('/address\s+(\d+)/', $errorMessage, $matches);
                    $address = $matches[1] ?? 0;
                    throw new InvalidAddressException($address, $unitId, $errorMessage, 0, $e);
                }

                throw $e;
            } catch (Exception $e) {
                Log::error("Error during Modbus operation for Unit ID {$unitId}: ".$e->getMessage());
                throw $e;
            } finally {
                if ($this->modbus->isCurrentlyConnected()) {
                    $this->modbus->close();
                    Log::info("Connection to Unit ID {$unitId} closed after Modbus operation.");
                }
            }
        });
    }

    /**
     * Don't run without a lock.
     * Make sure to set the lock before calling this function
     *
     * Connect to Unit ID bevor fechting the Lock Status.
     *
     **@throws Exception
     */
    private function fetchLockerStatus(Locker $locker): LockerStatus
    {

        if ($locker->unit_id !== $this->getUnitId()) {
            throw new Exception('Locker Unit ID mismatches the current connection. Reconnect to the correct unit.');
        }

        try {
            // Die readDiscreteInputs sollte idealerweise den Timeout des Clients verwenden.
            $result = $this->modbus->readDiscreteInputs($locker->input_address, 1);
            if (empty($result) || ! isset($result[$locker->input_address])) {
                return LockerStatus::Unreachable;
            }

            $statusValue = $result[$locker->input_address];

            return match ((int) $statusValue) {
                0 => LockerStatus::Open,
                1 => LockerStatus::Closed,
                default => LockerStatus::Unknown,
            };

        } catch (Exception $e) {
            Log::error('Failed to fetch locker status for Unit ID '.$locker->name.' (Unit ID: '.$locker->unit_id.').'.$e->getMessage());

            return LockerStatus::Unreachable;
        }
    }

    private function updateLockerStatus(Locker $locker, LockerStatus $newStatus): void
    {
        $oldStatus = $locker->status;
        $locker->update(['status' => $newStatus->value]);

        // Ereignis für Statusänderung auslösen
        //            event(new LockerStatusChanged($locker,
        //                "Locker status changed from {$oldStatus->value} to {$newStatus->value}",
        //                $oldStatus,
        //                $newStatus));

    }

    /**
     * @throws LockTimeoutException
     */
    public function pollAndUpdateAllLockerStatuses(?OutputStyle $output = null): void
    {
        $lockersByUnit = Locker::all()->groupBy('unit_id');
        $modbusBaseTimeout = $this->getModbusResponseTimeoutInSeconds();

        foreach ($lockersByUnit as $unitId => $lockersInUnit) {
            if (empty($unitId) || $lockersInUnit->isEmpty()) {
                $output?->writeln('Found lockers with empty unit_id or no lockers for unit, skipping.');

                continue;
            }

            $estimatedTimePerLocker = $modbusBaseTimeout + 0.1;
            $totalEstimatedTimeForUnit = $lockersInUnit->count() * $estimatedTimePerLocker;
            $lockTtlForUnit = (int) ceil($totalEstimatedTimeForUnit + self::LOCK_TIMEOUT_BUFFER_SECONDS);

            $blockFor = $lockTtlForUnit; // Max. Wartezeit = Lock-Zeit

            try {

                $this->executeModbusOperation($unitId, function (ModbusClient $modbusClient) use ($lockersInUnit, $output) {
                    foreach ($lockersInUnit as $locker) {
                        $currentDbStatus = $locker->status;
                        try {
                            $newStatus = $this->fetchLockerStatus($locker);

                            if ($currentDbStatus !== $newStatus) {
                                $this->updateLockerStatus($locker, $newStatus);
                                $output?->writeln("Locker {$locker->id} (Unit {$locker->unit_id}): Status changed from {$currentDbStatus->value} to {$newStatus->value}");
                            }
                        } catch (Exception $e) {
                            $output?->writeln("<fg=red>Error fetching status for Locker {$locker->name} (ID: {$locker->id}): ".$e->getMessage().'</>');
                            // Optional: Den Status dieses speziellen Lockers auf 'Unreachable' setzen
                            $this->updateLockerStatus($locker, LockerStatus::Unreachable);
                        }
                    }
                }, $lockTtlForUnit, $blockFor);

            } catch (LockTimeoutException $e) {
                $output?->writeln("<fg=yellow>Could not acquire lock for Unit ID {$unitId} within {$blockFor}s. Skipping this unit for now.</>");
            } catch (Exception $e) {
                $output?->writeln("<fg=red>Error processing unit ID {$unitId}: ".$e->getMessage().'</>');
                Locker::query()->where('unit_id', $unitId)->update(['status' => LockerStatus::Unreachable->value]);
            }
        }
    }

    public function setUnitID(int $int): void
    {
        $this->modbus->setSlave($int);
    }

    public function connectToUnit(int $id): void
    {

        Log::info('Attempting to connect to Unit ID '.$id);
        // Prüfen, ob bereits mit der korrekten Unit ID verbunden
        if ($this->modbus->isCurrentlyConnected() && $this->getUnitId() === $id) {
            Log::info('Already connected to Unit ID '.$id);

            return; // Bereits korrekt verbunden
        }

        // Wenn verbunden, aber mit falscher Unit ID, oder wenn Unit ID nicht gesetzt ist
        if ($this->modbus->isCurrentlyConnected()) {
            Log::info('Closing existing connection to Unit ID '.$this->getUnitId().' before switching to '.$id);
            $this->modbus->close();
        }

        Log::info('Setting slave to Unit ID '.$id);
        $this->setUnitID($id);
        Log::info('Connecting to Modbus...');
        $this->modbus->connect(); // Dies könnte eine Exception werfen, die von executeModbusOperation gefangen wird
        Log::info('Successfully connected to Unit ID '.$id);
    }

    /**
     * @throws LockTimeoutException
     * @throws Exception
     */
    public function openLocker(Locker $locker): void
    {
        // Die Lock-TTL wird nun von executeModbusOperation intern berechnet,
        // basierend auf dem einzelnen Modbus-Timeout.
        $this->executeModbusOperation($locker->unit_id, function (ModbusClient $modbusClient) use ($locker) {
            $coilAddress = $locker->coil_address;
            Log::info('Executing openLocker operations for Locker ID: '.$locker->id.' (Unit ID: '.$locker->unit_id.')');

            try {
                Log::info("Open Locker (Coil: {$coilAddress})");
                $modbusClient->writeSingleCoil($coilAddress, true);
            } catch (Exception $e) {
                Log::error("Error opening the Locker (Coil: {$coilAddress}): ".$e->getMessage());
                throw $e;
            }

            Sleep::sleep(0.2); // Ensures that our lock just gets a impulse

            try {
                Log::info("Close Locker (Coil: {$coilAddress})");
                $modbusClient->writeSingleCoil($coilAddress, false);
            } catch (Exception $e) {
                Log::critical("Error while closing the Locker (Coil: {$coilAddress}): ".$e->getMessage());
                throw $e;
            } finally {
                try {
                    $status = $modbusClient->readCoils($coilAddress, 1);
                    if ($status[$coilAddress] === true) {
                        Log::critical("Coil {$coilAddress} for Locker ID {$locker->id} was still active. Attempting to turn OFF again.");
                        $modbusClient->writeSingleCoil($coilAddress, false);
                    }
                } catch (Exception $e) {
                    Log::critical("CRITICAL: FAILED to turn OFF coil {$coilAddress} for Locker ID: {$locker->id} in FINALLY block. Error: ".$e->getMessage());
                    throw $e;
                }
            }
            Log::info('Finished openLocker operations for Locker ID: '.$locker->id);
        }); // lockTtl und blockFor werden von executeModbusOperation mit Standardwerten oder berechneten Werten belegt
    }

    public function __destruct()
    {
        if (isset($this->modbus) && $this->modbus->isCurrentlyConnected()) {
            $this->modbus->close();
        }
    }
}
