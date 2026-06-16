import PQueue from "p-queue";
import type { CompartmentTarget, DoorState } from "../../domain/compartment";
import { ConnectionState, LockerBusPort } from "../../ports/locker-bus.port";
export interface ModbusDriver {
    connect(): Promise<void>;
    disconnect(): Promise<void>;
    isOpen(): boolean;
    flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void>;
    readCoils(slaveId: number, address: number, length: number): Promise<boolean[]>;
    readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]>;
    turnAllRelaysOff(slaveId: number): Promise<void>;
}
export declare class ModbusBusActor implements LockerBusPort {
    private readonly driver;
    private readonly configuredSlaveIds;
    private queue;
    private connectionState;
    private readonly reconnect;
    constructor(driver: ModbusDriver, reconnectOptions?: {
        maxAttempts?: number;
        delayMs?: number;
    }, configuredSlaveIds?: number[]);
    connect(): Promise<void>;
    disconnect(): Promise<void>;
    getConnectionState(): ConnectionState;
    getConfiguredSlaveIds(): number[];
    flashRelay(target: CompartmentTarget, durationMs: number): Promise<void>;
    readRelayState(target: CompartmentTarget): Promise<boolean>;
    readDoorSensor(target: CompartmentTarget): Promise<DoorState>;
    turnAllRelaysOff(slaveId: number): Promise<void>;
    ensureConnected(): Promise<boolean>;
    getQueue(): PQueue;
    private connectInternal;
    private run;
}
