<?php

namespace OpenLocker\PhpModbusFfi;

use OpenLocker\PhpModbusFfi\Exceptions\ModbusConfigurationException;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusConnectionException;
use OpenLocker\PhpModbusFfi\Exceptions\ModbusException;

/**
 * Modbus TCP Connection.
 */
class ModbusTcpConnection extends ModbusConnection
{
    private string $ip;

    private int $port;

    /**
     * @param  string  $ip  IP Address of the Modbus TCP server.
     * @param  int  $port  Port (usually 502).
     * @param  string|null  $libraryPath  Path to libmodbus shared library.
     *
     * @throws ModbusException If FFI not loaded.
     * @throws ModbusConfigurationException If library load fails.
     * @throws ModbusConnectionException If context creation fails.
     */
    public function __construct(string $ip, int $port = 502, ?string $libraryPath = null)
    {
        parent::__construct($libraryPath);
        $this->ip = $ip;
        $this->port = $port;
        $this->initializeContext();
    }

    /** {@inheritdoc} */
    protected function initializeContext(): void
    {
        $this->ctx = $this->ffi->modbus_new_tcp($this->ip, $this->port);
        if (\FFI::isNull($this->ctx)) {
            // modbus_new_tcp might not set errno reliably on failure.
            throw new ModbusConnectionException("Failed to create Modbus TCP context for {$this->ip}:{$this->port}. Check IP/Port and library access.");
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
            $this->throwLastError("Failed to connect to TCP server {$this->ip}:{$this->port}");
        }
        $this->isConnected = true;
    }

    /**
     * Returns the IP address of the connection.
     */
    public function getIpAddress(): string
    {
        return $this->ip;
    }

    /**
     * Returns the port of the connection.
     */
    public function getPort(): int
    {
        return $this->port;
    }
}
