<?php

namespace OpenLocker\PhpModbusFfi;

/**
 * Modbus RTU (Serial) Connection.
 */
class ModbusRtuConnection extends ModbusConnection
{
    private string $device;

    private int $baud;

    private string $parity; // 'N', 'E', 'O'

    private int $dataBits; // 7, 8

    private int $stopBits; // 1, 2

    /**
     * @param  string  $device  Serial device path (e.g., /dev/ttyUSB0, COM3).
     * @param  int  $baud  Baud rate (e.g., 9600, 19200, 115200).
     * @param  string  $parity  Parity ('N' for None, 'E' for Even, 'O' for Odd).
     * @param  int  $dataBits  Data bits (usually 8, sometimes 7).
     * @param  int  $stopBits  Stop bits (usually 1, sometimes 2).
     * @param  string|null  $libraryPath  Path to libmodbus shared library.
     *
     * @throws ModbusException If FFI not loaded.
     * @throws ModbusConfigurationException If library load fails or invalid RTU parameters.
     * @throws ModbusConnectionException If context creation fails.
     */
    public function __construct(
        string $device,
        int $baud = 9600,
        string $parity = 'N',
        int $dataBits = 8,
        int $stopBits = 1,
        ?string $libraryPath = null
    ) {
        parent::__construct($libraryPath);

        // Validate parameters
        $parityUpper = strtoupper($parity);
        if (! in_array($parityUpper, ['N', 'E', 'O'])) {
            throw new ModbusConfigurationException("Invalid parity setting: '{$parity}'. Must be 'N', 'E', or 'O'.");
        }
        if (! in_array($dataBits, [7, 8])) {
            throw new ModbusConfigurationException("Invalid data bits setting: {$dataBits}. Must be 7 or 8.");
        }
        if (! in_array($stopBits, [1, 2])) {
            throw new ModbusConfigurationException("Invalid stop bits setting: {$stopBits}. Must be 1 or 2.");
        }

        $this->device = $device;
        $this->baud = $baud;
        $this->parity = $parityUpper;
        $this->dataBits = $dataBits;
        $this->stopBits = $stopBits;

        $this->initializeContext();
    }

    /** {@inheritdoc} */
    protected function initializeContext(): void
    {
        $this->ctx = $this->ffi->modbus_new_rtu(
            $this->device,
            $this->baud,
            $this->parity, // Pass validated char
            $this->dataBits,
            $this->stopBits
        );

        if (\FFI::isNull($this->ctx)) {
            // modbus_new_rtu might not set errno reliably on failure.
            // Check permissions for the serial device!
            throw new ModbusConnectionException("Failed to create Modbus RTU context for device '{$this->device}'. Check device path, permissions, and parameters.");
        }
    }

    /**
     * {@inheritdoc}
     * Overridden to provide more specific error message.
     */
    public function connect(): void
    {
        $this->checkContext();
        if ($this->isConnected) {
            return;
        }
        if ($this->ffi->modbus_connect($this->ctx) === -1) {
            $this->isConnected = false;
            // Use throwLastError to get errno details
            $this->throwLastError("Failed to connect to RTU device '{$this->device}'");
        }
        $this->isConnected = true;
    }

    // --- Getters for RTU parameters ---
    public function getDevice(): string
    {
        return $this->device;
    }

    public function getBaudRate(): int
    {
        return $this->baud;
    }

    public function getParity(): string
    {
        return $this->parity;
    }

    public function getDataBits(): int
    {
        return $this->dataBits;
    }

    public function getStopBits(): int
    {
        return $this->stopBits;
    }

    // --- RTU Specific Settings (Optional - could add methods) ---
    /*
    public function setSerialMode(int $mode): void {
    // Requires modbus_rtu_set_serial_mode in CDEF
    // Example: MODBUS_RTU_RS232 or MODBUS_RTU_RS485
    $this->checkContext();
    if ($this->ffi->modbus_rtu_set_serial_mode($this->ctx, $mode) == -1) {
    $this->throwLastError("Failed to set RTU serial mode");
    }
    }

    public function setRts(int $mode): void {
    // Requires modbus_rtu_set_rts in CDEF
    // Example: MODBUS_RTU_RTS_NONE, MODBUS_RTU_RTS_UP, MODBUS_RTU_RTS_DOWN
    $this->checkContext();
    if ($this->ffi->modbus_rtu_set_rts($this->ctx, $mode) == -1) {
    $this->throwLastError("Failed to set RTU RTS mode");
    }
    }
    */
}
