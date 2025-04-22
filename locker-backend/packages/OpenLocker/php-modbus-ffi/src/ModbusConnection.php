<?php

namespace OpenLocker\PhpModbusFfi;

use FFI;
use LogicException;
use OpenLocker\PhpModbusFfi\Contracts\ModbusClient;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusConfigurationException;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusConnectionException;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusException;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException;

abstract class ModbusConnection implements ModbusClient
{
    protected \FFI $ffi;

    protected ?\FFI\CData $ctx = null; // Pointer to modbus_t*

    protected bool $isConnected = false;

    protected int $slaveId = 1; // Default slave ID

    // Standard-Bibliotheksname. Kann durch Konstruktor-Parameter überschrieben werden.
    protected string $libraryNameOrPath = 'libmodbus.so.5';

    // --- FFI Definitions (Common for TCP & RTU) ---
    // This should ideally match the installed libmodbus version's header (modbus.h)
    protected const CDEF = '
// Basic types (adjust if needed based on your system/compiler)
typedef unsigned char uint8_t;
typedef unsigned short uint16_t;

// Opaque struct pointer
typedef struct _modbus modbus_t;

// --- Core Functions ---
void modbus_free(modbus_t *ctx);
int modbus_set_slave(modbus_t *ctx, int slave);
int modbus_get_slave(modbus_t *ctx); // Added getter
int modbus_connect(modbus_t *ctx);
void modbus_close(modbus_t *ctx);
const char *modbus_strerror(int errnum);

// --- Configuration ---
int modbus_set_debug(modbus_t *ctx, int boolean); // Useful for debugging
int modbus_set_error_recovery(modbus_t *ctx, int error_recovery); // MODBUS_ERROR_RECOVERY_LINK, MODBUS_ERROR_RECOVERY_PROTOCOL
void modbus_set_response_timeout(modbus_t *ctx, uint32_t tv_sec, uint32_t tv_usec);
void modbus_get_response_timeout(modbus_t *ctx, uint32_t *tv_sec, uint32_t *tv_usec);
// void modbus_set_byte_timeout(modbus_t *ctx, uint32_t tv_sec, uint32_t tv_usec); // RTU specific, might add later if needed
// void modbus_get_byte_timeout(modbus_t *ctx, uint32_t *tv_sec, uint32_t *tv_usec); // RTU specific

// --- Modbus Function Codes ---
// FC 01: Read Coils
int modbus_read_bits(modbus_t *ctx, int addr, int nb, uint8_t *dest);
// FC 02: Read Discrete Inputs
int modbus_read_input_bits(modbus_t *ctx, int addr, int nb, uint8_t *dest);
// FC 03: Read Holding Registers
int modbus_read_registers(modbus_t *ctx, int addr, int nb, uint16_t *dest);
// FC 04: Read Input Registers
int modbus_read_input_registers(modbus_t *ctx, int addr, int nb, uint16_t *dest);
// FC 05: Write Single Coil
int modbus_write_bit(modbus_t *ctx, int coil_addr, int status); // status: 0=OFF, 1=ON
// FC 06: Write Single Register
int modbus_write_register(modbus_t *ctx, int reg_addr, uint16_t value);
// FC 15: Write Multiple Coils
int modbus_write_bits(modbus_t *ctx, int addr, int nb, const uint8_t *data);
// FC 16: Write Multiple Registers
int modbus_write_registers(modbus_t *ctx, int addr, int nb, const uint16_t *data);
// FC 23: Read/Write Multiple Registers (Less common, add if needed)
// int modbus_write_and_read_registers(modbus_t *ctx, int write_addr, int write_nb, const uint16_t *src, int read_addr, int read_nb, uint16_t *dest);

// --- Specific Connection Types (Need to be in CDEF for FFI) ---
// TCP
modbus_t* modbus_new_tcp(const char *ip_address, int port);
// RTU
modbus_t* modbus_new_rtu(const char *device, int baud, char parity, int data_bit, int stop_bit);

// Access to errno for error details
extern int errno;
';

    /**
     * Constructor.
     *
     * @param  string|null  $libraryPath  Specific library name or path, or null to use default.
     *
     * @throws ModbusException If FFI extension is not loaded.
     * @throws ModbusConfigurationException If FFI initialization fails.
     */
    public function __construct(?string $libraryPath = null)
    {
        if (! extension_loaded('ffi')) {
            throw new ModbusException('FFI extension is not loaded. Please enable it in php.ini.');
        }

        if ($libraryPath !== null) {
            $this->libraryNameOrPath = $libraryPath;
        }

        try {
            // Define C functions and load the library
            $this->ffi = \FFI::cdef($this->getCombinedCDef(), $this->libraryNameOrPath);
        } catch (\FFI\Exception $e) {
            throw new ModbusConfigurationException("FFI Error: Failed to load library '{$this->libraryNameOrPath}' or parse definitions: ".$e->getMessage(), 0, $e);
        }

    }

    /**
     * Allows concrete classes to add their specific C definitions if needed.
     *
     * @return string The complete CDEF string.
     */
    protected function getCombinedCDef(): string
    {
        // Currently, all needed defs are in the main CDEF const.
        // This method is here for potential future extensions in child classes.
        return self::CDEF;
    }

    /**
     * Initializes the Modbus context using the appropriate libmodbus function.
     * Must be called by the concrete class constructor.
     *
     * @throws ModbusConnectionException If context creation fails.
     */
    abstract protected function initializeContext(): void;

    /**
     * Sets the slave ID for the next transaction.
     * Should ideally be called before connect(), but libmodbus allows changing it later too.
     *
     * @param  int  $slaveId  The Modbus slave ID (usually 1-247).
     *
     * @throws ModbusConnectionException If setting the slave ID fails.
     * @throws LogicException If the context is not initialized.
     */
    public function setSlave(int $slaveId): void
    {
        $this->checkContext();
        if ($this->ffi->modbus_set_slave($this->ctx, $slaveId) === -1) {
            $this->throwLastError("Failed to set slave ID to {$slaveId}");
        }
        $this->slaveId = $slaveId;
    }

    /**
     * Gets the current slave ID configured in the context.
     *
     * @return int Slave ID.
     *
     * @throws LogicException If the context is not initialized.
     */
    public function getSlave(): int
    {
        $this->checkContext();

        // Use the stored value as modbus_get_slave might not be reliable before connect?
        // Or call the FFI function:
        // $id = $this->ffi->modbus_get_slave($this->ctx);
        // if ($id == -1) { // Check if -1 is a valid return or error indicator
        //     $this->throwLastError("Failed to get slave ID");
        // }
        // return $id;
        return $this->slaveId;
    }

    /**
     * Sets the response timeout.
     *
     * @param  int  $seconds  Seconds part of the timeout.
     * @param  int  $microseconds  Microseconds part of the timeout.
     *
     * @throws LogicException If the context is not initialized.
     */
    public function setResponseTimeout(int $seconds, int $microseconds): void
    {
        $this->checkContext();
        $this->ffi->modbus_set_response_timeout($this->ctx, $seconds, $microseconds);
    }

    /**
     * Gets the current response timeout.
     *
     * @return array{seconds: int, microseconds: int}
     *
     * @throws LogicException If the context is not initialized.
     * @throws ModbusIOException If getting the timeout fails.
     */
    public function getResponseTimeout(): array
    {
        $this->checkContext();
        $sec = $this->ffi->new('uint32_t');
        $usec = $this->ffi->new('uint32_t');
        // Note: modbus_get_response_timeout doesn't return error status directly in older versions.
        // We assume it works if context is valid. Newer versions might return int. Check your libmodbus doc.
        $this->ffi->modbus_get_response_timeout($this->ctx, \FFI::addr($sec), \FFI::addr($usec));

        return ['seconds' => $sec->cdata, 'microseconds' => $usec->cdata];
    }

    /**
     * Enables or disables debug mode (prints communication details).
     *
     * @param  bool  $enable  True to enable debug, false to disable.
     *
     * @throws LogicException If the context is not initialized.
     * @throws ModbusConfigurationException If setting debug mode fails.
     */
    public function setDebug(bool $enable): void
    {
        $this->checkContext();
        if ($this->ffi->modbus_set_debug($this->ctx, $enable ? 1 : 0) === -1) {
            // This function might not set errno reliably, check libmodbus docs
            throw new ModbusConfigurationException('Failed to set debug mode to '.($enable ? 'ON' : 'OFF'));
        }
    }

    /**
     * Sets the error recovery mode.
     * Example: MODBUS_ERROR_RECOVERY_LINK | MODBUS_ERROR_RECOVERY_PROTOCOL
     * Constants are usually defined in modbus.h, you might need to define them in PHP.
     *
     * @param  int  $mode  Error recovery flags.
     *
     * @throws LogicException If the context is not initialized.
     * @throws ModbusConfigurationException If setting recovery mode fails.
     */
    public function setErrorRecovery(int $mode): void
    {
        $this->checkContext();
        if ($this->ffi->modbus_set_error_recovery($this->ctx, $mode) === -1) {
            $this->throwLastError('Failed to set error recovery mode');
        }
    }

    /**
     * Connects to the Modbus slave/server.
     *
     * @throws ModbusConnectionException If the connection fails.
     * @throws LogicException If the context is not initialized.
     */
    public function connect(): void
    {
        $this->checkContext();
        if ($this->isConnected) {
            return; // Already connected
        }
        if ($this->ffi->modbus_connect($this->ctx) === -1) {
            $this->isConnected = false; // Ensure state is correct
            $this->throwLastError('Failed to connect'); // Specific details depend on subclass (IP/Port or Device)
        }
        $this->isConnected = true;
    }

    /**
     * Closes the Modbus connection.
     */
    public function close(): void
    {
        if ($this->isConnected && ! \FFI::isNull($this->ctx)) {
            $this->ffi->modbus_close($this->ctx);
            $this->isConnected = false;
        }
    }

    /**
     * Destructor: Ensures connection is closed and resources are freed.
     */
    public function __destruct()
    {
        $this->close(); // Attempt to close gracefully
        if (! \FFI::isNull($this->ctx)) {
            $this->ffi->modbus_free($this->ctx);
            $this->ctx = null; // Prevent double free
        }
    }

    // --- Modbus Read Functions ---

    /**
     * Reads one or more coils (FC 01).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of coils to read.
     * @return array<int, bool> Array of boolean values indexed by address.
     *
     * @throws ModbusIOException If read fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function readCoils(int $address, int $count): array
    {
        $this->ensureConnected();
        if ($count <= 0) {
            return [];
        }

        // Allocate C buffer (uint8_t per bit)
        $buffer = $this->ffi->new("uint8_t[{$count}]");

        $result = $this->ffi->modbus_read_bits($this->ctx, $address, $count, $buffer);

        if ($result === -1) {
            $this->throwLastError("Failed to read {$count} coils from address {$address}");
        }

        // Convert C uint8_t[] to PHP bool[]
        $coils = [];
        for ($i = 0; $i < $result; $i++) { // Use actual count read
            $coils[$address + $i] = ($buffer[$i] === 1); // Assuming 1=ON, 0=OFF
        }

        return $coils;
    }

    /**
     * Reads one or more discrete inputs (FC 02).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of inputs to read.
     * @return array<int, bool> Array of boolean values indexed by address.
     *
     * @throws ModbusIOException If read fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function readDiscreteInputs(int $address, int $count): array
    {
        $this->ensureConnected();
        if ($count <= 0) {
            return [];
        }

        $buffer = $this->ffi->new("uint8_t[{$count}]");
        $result = $this->ffi->modbus_read_input_bits($this->ctx, $address, $count, $buffer);

        if ($result === -1) {
            $this->throwLastError("Failed to read {$count} discrete inputs from address {$address}");
        }

        $inputs = [];
        for ($i = 0; $i < $result; $i++) {
            $inputs[$address + $i] = ($buffer[$i] === 1);
        }

        return $inputs;
    }

    /**
     * Reads one or more holding registers (FC 03).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of registers to read.
     * @return array<int, int> Array of integer values (uint16) indexed by address.
     *
     * @throws ModbusIOException If read fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function readHoldingRegisters(int $address, int $count): array
    {
        $this->ensureConnected();
        if ($count <= 0) {
            return [];
        }

        $buffer = $this->ffi->new("uint16_t[{$count}]");
        $result = $this->ffi->modbus_read_registers($this->ctx, $address, $count, $buffer);

        if ($result === -1) {
            $this->throwLastError("Failed to read {$count} holding registers from address {$address}");
        }

        $registers = [];
        for ($i = 0; $i < $result; $i++) {
            $registers[$address + $i] = $buffer[$i]; // uint16 value
        }

        return $registers;
    }

    /**
     * Reads one or more input registers (FC 04).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of registers to read.
     * @return array<int, int> Array of integer values (uint16) indexed by address.
     *
     * @throws ModbusIOException If read fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function readInputRegisters(int $address, int $count): array
    {
        $this->ensureConnected();
        if ($count <= 0) {
            return [];
        }

        $buffer = $this->ffi->new("uint16_t[{$count}]");
        $result = $this->ffi->modbus_read_input_registers($this->ctx, $address, $count, $buffer);

        if ($result === -1) {
            $this->throwLastError("Failed to read {$count} input registers from address {$address}");
        }

        $registers = [];
        for ($i = 0; $i < $result; $i++) {
            $registers[$address + $i] = $buffer[$i];
        }

        return $registers;
    }

    // --- Modbus Write Functions ---

    /**
     * Writes a single coil (FC 05).
     *
     * @param  int  $address  Coil address.
     * @param  bool  $status  True for ON (1), False for OFF (0).
     *
     * @throws ModbusIOException If write fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function writeSingleCoil(int $address, bool $status): void
    {
        $this->ensureConnected();
        $value = $status ? 1 : 0;
        if ($this->ffi->modbus_write_bit($this->ctx, $address, $value) === -1) {
            $this->throwLastError("Failed to write single coil at address {$address} to ".($status ? 'ON' : 'OFF'));
        }
    }

    /**
     * Writes a single holding register (FC 06).
     *
     * @param  int  $address  Register address.
     * @param  int  $value  Value to write (0-65535).
     *
     * @throws ModbusIOException If write fails.
     * @throws LogicException If not connected or context invalid.
     * @throws \InvalidArgumentException If value is out of uint16 range.
     */
    public function writeSingleRegister(int $address, int $value): void
    {
        $this->ensureConnected();
        if ($value < 0 || $value > 65535) {
            throw new \InvalidArgumentException("Register value must be between 0 and 65535. Got: {$value}");
        }
        if ($this->ffi->modbus_write_register($this->ctx, $address, $value) === -1) {
            $this->throwLastError("Failed to write single register at address {$address} with value {$value}");
        }
    }

    /**
     * Writes multiple coils (FC 15).
     *
     * @param  int  $address  Start address.
     * @param  array<bool>  $values  Array of boolean values to write.
     *
     * @throws ModbusIOException If write fails.
     * @throws LogicException If not connected or context invalid.
     */
    public function writeMultipleCoils(int $address, array $values): void
    {
        $this->ensureConnected();
        $count = count($values);
        if ($count === 0) {
            return;
        }

        // Create C buffer (uint8_t[]) from PHP bool[]
        $buffer = $this->ffi->new("uint8_t[{$count}]");
        $i = 0;
        foreach ($values as $value) {
            $buffer[$i++] = $value ? 1 : 0;
        }

        if ($this->ffi->modbus_write_bits($this->ctx, $address, $count, $buffer) === -1) {
            $this->throwLastError("Failed to write {$count} coils starting at address {$address}");
        }
    }

    /**
     * Writes multiple holding registers (FC 16).
     *
     * @param  int  $address  Start address.
     * @param  array<int>  $values  Array of integer values (0-65535) to write.
     *
     * @throws ModbusIOException If write fails.
     * @throws LogicException If not connected or context invalid.
     * @throws \InvalidArgumentException If any value is out of uint16 range.
     */
    public function writeMultipleRegisters(int $address, array $values): void
    {
        $this->ensureConnected();
        $count = count($values);
        if ($count === 0) {
            return;
        }

        // Create C buffer (uint16_t[]) from PHP int[] and validate values
        $buffer = $this->ffi->new("uint16_t[{$count}]");
        $i = 0;
        foreach ($values as $value) {
            if ($value < 0 || $value > 65535) {
                throw new \InvalidArgumentException("Register value must be between 0 and 65535. Got: {$value} at index {$i}");
            }
            $buffer[$i++] = $value;
        }

        if ($this->ffi->modbus_write_registers($this->ctx, $address, $count, $buffer) === -1) {
            $this->throwLastError("Failed to write {$count} registers starting at address {$address}");
        }
    }

    // --- Helper Methods ---

    /** Checks if the Modbus context is initialized. */
    protected function checkContext(): void
    {
        if (\FFI::isNull($this->ctx)) {
            throw new LogicException('Modbus context is not initialized. Call constructor of concrete class (ModbusTcpConnection or ModbusRtuConnection).');
        }
    }

    /** Checks if the connection is established. */
    protected function ensureConnected(): void
    {
        $this->checkContext(); // Also check context validity
        if (! $this->isConnected) {
            throw new ModbusConnectionException('Not connected to Modbus server/device.');
        }
    }

    protected function throwLastError(string $messagePrefix): void
    {
        if (! isset($this->ffi) || ! $this->ffi instanceof \FFI) {
            throw new \LogicException('FFI object is not initialized before calling throwLastError.');
        }

        $errno = $this->ffi->errno; // Read errno immediately

        if ($errno !== 0) {
            $returnValue = null; // Variable für den Rückgabewert

            try {
                // Rufen Sie die C-Funktion auf
                $returnValue = $this->ffi->modbus_strerror($errno);

                // --- NEUE PRÜFUNG: Typ des Rückgabewertes ---
                $returnValueType = gettype($returnValue);
                if (is_string($returnValue)) {
                    $errorMsg = $returnValue;
                    // Zusätzliche Prüfung auf leeren String, falls das vorkommt
                    if (empty(trim($errorMsg))) {
                        $errorMsg = 'System error or unknown libmodbus error code (modbus_strerror returned empty string)';
                    }
                }
                // Fall 2: Erwartetes FFI\CData-Objekt (C-Pointer) erhalten
                elseif ($returnValue instanceof \FFI\CData) {
                    $isNull = \FFI::isNull($returnValue); // Prüfen, ob der Pointer NULL ist

                    if (! $isNull) {
                        try {
                            $errorMsg = \FFI::string($returnValue);
                        } catch (\Throwable $e) {
                            $errorMsg = 'Error converting error string from CData.';
                        }
                    } else {
                        $errorMsg = 'System error or unknown libmodbus error code (modbus_strerror returned NULL pointer)';
                    }
                }
                // Fall 3: Etwas völlig anderes oder NULL zurückbekommen
                else {
                    $errorMsg = "Internal library error: Unexpected return type ({$returnValueType}) from modbus_strerror.";
                }

            } catch (\Throwable $e) {
                $errorMsg = 'Error retrieving error string from libmodbus.';
            }

        } else {
            $errorMsg = 'Error handler called unexpectedly with errno 0.';
        }

        // Bestimme die Exception-Klasse basierend auf dem Verbindungsstatus
        $exceptionClass = ModbusIOException::class;
        if (! $this->isConnected) {
            $exceptionClass = ModbusConnectionException::class;
        }

        // Werfe die Exception mit der ermittelten Nachricht und Klasse
        throw new $exceptionClass("{$messagePrefix}: {$errorMsg} (errno {$errno})");
    }
}
