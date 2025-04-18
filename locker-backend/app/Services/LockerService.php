<?php

namespace App\Services;

use App\Models\Locker;
use Illuminate\Support\Facades\Log;

// use ModbusMaster; // We are replacing this

class LockerService
{
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
        // Define parameters based on the locker model or configuration
        $host = '192.168.178.200'; // Example: Get from locker or config
        $port = 4196;                   // Example: Get from locker or config
        $unitId = 1;                 // Example: Get from locker or config
        $coilAddress = 5;       // Example: Get from locker or config
        $valueToWrite = 1; // 1 for ON (open)
        $timeout = 3.0;

        // Verwenden Sie den Python-Interpreter aus der virtuellen Umgebung
        $pythonExecutable = '/var/www/html/scripts/venv/bin/python';
        $scriptPath = base_path('scripts/modbus_cli_venv.py'); // Use Laravel's base_path helper

        if (! file_exists($pythonExecutable)) {
            Log::error("Python-Interpreter der virtuellen Umgebung nicht gefunden: {$pythonExecutable}");

            return false;
        }
        if (! file_exists($scriptPath)) {
            Log::error("Modbus-Script nicht gefunden: {$scriptPath}");

            return false;
        }

        // Stellen Sie sicher, dass das Script ausführbar ist
        if (! is_executable($scriptPath)) {
            Log::warning("Modbus-Script ist nicht ausführbar: {$scriptPath}. Versuche es trotzdem auszuführen.");
            // Bei der ersten Ausführung sollten Sie das Script ausführbar machen:
            // chmod +x {$scriptPath}
        }

        // Construct the command
        $command = sprintf(
            '%s %s --host %s --port %d --unit %d --action write_coil --address %d --value %d --timeout %.1f 2>&1',
            escapeshellarg($pythonExecutable),
            escapeshellarg($scriptPath),
            escapeshellarg($host),
            $port,
            $unitId,
            $coilAddress,
            $valueToWrite,
            $timeout
        );

        Log::info("Executing Modbus command: {$command}");

        // Execute the command
        $output = shell_exec($command);
        $exitCode = null; // shell_exec doesn't directly give exit code easily in a cross-platform way

        Log::info('Modbus script output: '.trim($output ?? ''));

        // Check the output
        if ($output === null) {
            Log::error("Failed to execute Modbus script or no output received. Check PHP error logs (permissions, path issues?). Command: {$command}");

            return false;
        }

        $trimmedOutput = trim($output);

        if (str_starts_with($trimmedOutput, 'ERROR:')) {
            Log::error("Modbus script returned an error: {$trimmedOutput}");

            return false;
        }

        if ($trimmedOutput === 'OK') {
            Log::info("Locker {$locker->id} opened successfully via Modbus CLI.");

            return true;
        } else {
            Log::error("Unexpected output from Modbus script: {$trimmedOutput}");

            return false;
        }
    }

    public function getLockerStatus(Locker $locker): bool
    {
        // Similar implementation for reading status using 'read_coil'
        // Placeholder implementation
        Log::warning('getLockerStatus is not fully implemented yet using Modbus CLI.');

        return true; // Replace with actual logic
    }
}
