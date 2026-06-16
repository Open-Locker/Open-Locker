import type { CompartmentTarget, DoorState } from "../../src/domain/compartment";
import type { LockerBusPort } from "../../src/ports/locker-bus.port";
export declare class FakeLockerBus implements LockerBusPort {
    readonly flashCalls: Array<{
        target: CompartmentTarget;
        durationMs: number;
    }>;
    readonly writeCoilCalls: Array<{
        slaveId: number;
        address: number;
        value: boolean;
    }>;
    readonly turnAllOffCalls: number[];
    private relayStates;
    private doorStates;
    private connected;
    private slaveIds;
    constructor(slaveIds?: number[]);
    connect(): Promise<void>;
    disconnect(): Promise<void>;
    getConnectionState(): "disconnected" | "connected";
    ensureConnected(): Promise<boolean>;
    flashRelay(target: CompartmentTarget, durationMs: number): Promise<void>;
    recordWriteCoil(slaveId: number, address: number, value: boolean): void;
    readRelayState(target: CompartmentTarget): Promise<boolean>;
    readDoorSensor(target: CompartmentTarget): Promise<DoorState>;
    turnAllRelaysOff(slaveId: number): Promise<void>;
    getConfiguredSlaveIds(): number[];
    reloadRuntimeConfig: () => Promise<void>;
    setRelayState(target: CompartmentTarget, on: boolean): void;
    private key;
}
