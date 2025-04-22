<?php

namespace OpenLocker\PhpModbusFfi\Contracts;

interface ModbusClient
{
    /**
     * Sets the slave ID for the next transaction.
     * Should ideally be called before connect(), but libmodbus allows changing it later too.
     *
     * @param  int  $slaveId  The Modbus slave ID (usually 1-247).
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusConnectionException If setting the slave ID fails.
     * @throws \LogicException If the context is not initialized.
     */
    public function setSlave(int $slaveId): void;

    /**
     * Gets the current slave ID configured in the context.
     *
     * @return int Slave ID.
     *
     * @throws \LogicException If the context is not initialized.
     */
    public function getSlave(): int;

    /**
     * Sets the response timeout.
     *
     * @param  int  $seconds  Seconds part of the timeout.
     * @param  int  $microseconds  Microseconds part of the timeout.
     *
     * @throws \LogicException If the context is not initialized.
     */
    public function setResponseTimeout(int $seconds, int $microseconds): void;

    /**
     * Gets the current response timeout.
     *
     * @return array{seconds: int, microseconds: int}
     *
     * @throws \LogicException If the context is not initialized.
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If getting the timeout fails.
     */
    public function getResponseTimeout(): array;

    /**
     * Enables or disables debug mode (prints communication details).
     *
     * @param  bool  $enable  True to enable debug, false to disable.
     *
     * @throws \LogicException If the context is not initialized.
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusConfigurationException If setting debug mode fails.
     */
    public function setDebug(bool $enable): void;

    /**
     * Sets the error recovery mode.
     * Example: MODBUS_ERROR_RECOVERY_LINK | MODBUS_ERROR_RECOVERY_PROTOCOL
     * Constants are usually defined in modbus.h, you might need to define them in PHP.
     *
     * @param  int  $mode  Error recovery flags.
     *
     * @throws \LogicException If the context is not initialized.
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusConfigurationException If setting recovery mode fails.
     */
    public function setErrorRecovery(int $mode): void;

    /**
     * Connects to the Modbus slave/server.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusConnectionException If the connection fails.
     * @throws \LogicException If the context is not initialized.
     */
    public function connect(): void;

    /**
     * Closes the Modbus connection.
     */
    public function close(): void;

    /**
     * Reads one or more coils (FC 01).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of coils to read.
     * @return array<int, bool> Array of boolean values indexed by address.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If read fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function readCoils(int $address, int $count): array;

    /**
     * Reads one or more discrete inputs (FC 02).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of inputs to read.
     * @return array<int, bool> Array of boolean values indexed by address.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If read fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function readDiscreteInputs(int $address, int $count): array;

    /**
     * Reads one or more holding registers (FC 03).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of registers to read.
     * @return array<int, int> Array of integer values (uint16) indexed by address.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If read fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function readHoldingRegisters(int $address, int $count): array;

    /**
     * Reads one or more input registers (FC 04).
     *
     * @param  int  $address  Start address.
     * @param  int  $count  Number of registers to read.
     * @return array<int, int> Array of integer values (uint16) indexed by address.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If read fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function readInputRegisters(int $address, int $count): array;

    /**
     * Writes a single coil (FC 05).
     *
     * @param  int  $address  Coil address.
     * @param  bool  $status  True for ON (1), False for OFF (0).
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If write fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function writeSingleCoil(int $address, bool $status): void;

    /**
     * Writes a single holding register (FC 06).
     *
     * @param  int  $address  Register address.
     * @param  int  $value  Value to write (0-65535).
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If write fails.
     * @throws \LogicException If not connected or context invalid.
     * @throws \InvalidArgumentException If value is out of uint16 range.
     */
    public function writeSingleRegister(int $address, int $value): void;

    /**
     * Writes multiple coils (FC 15).
     *
     * @param  int  $address  Start address.
     * @param  array<bool>  $values  Array of boolean values to write.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If write fails.
     * @throws \LogicException If not connected or context invalid.
     */
    public function writeMultipleCoils(int $address, array $values): void;

    /**
     * Writes multiple holding registers (FC 16).
     *
     * @param  int  $address  Start address.
     * @param  array<int>  $values  Array of integer values (0-65535) to write.
     *
     * @throws \OpenLocker\PhpModbusFfi\Exceptions\ModbusIOException If write fails.
     * @throws \LogicException If not connected or context invalid.
     * @throws \InvalidArgumentException If any value is out of uint16 range.
     */
    public function writeMultipleRegisters(int $address, array $values): void;

    // Fügen Sie hier alle öffentlichen Methoden hinzu,
    // die von außen aufgerufen werden sollen.
}
