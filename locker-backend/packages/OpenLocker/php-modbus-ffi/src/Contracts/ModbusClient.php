<?php

namespace OpenLocker\PhpModbusFfi\Contracts;

interface ModbusClient
{
    public function setSlave(int $slaveId): void;

    public function getSlave(): int;

    public function setResponseTimeout(int $seconds, int $microseconds): void;

    public function getResponseTimeout(): array;

    public function setDebug(bool $enable): void;

    public function setErrorRecovery(int $mode): void;

    public function connect(): void;

    public function close(): void;

    public function readCoils(int $address, int $count): array;

    public function readDiscreteInputs(int $address, int $count): array;

    public function readHoldingRegisters(int $address, int $count): array;

    public function readInputRegisters(int $address, int $count): array;

    public function writeSingleCoil(int $address, bool $status): void;

    public function writeSingleRegister(int $address, int $value): void;

    public function writeMultipleCoils(int $address, array $values): void;

    public function writeMultipleRegisters(int $address, array $values): void;

    // Fügen Sie hier alle öffentlichen Methoden hinzu,
    // die von außen aufgerufen werden sollen.
}
