import type { ModbusDriver } from "./bus-actor";
interface ModbusConnectionConfig {
    port: string;
    baudRate: number;
    dataBits: 7 | 8;
    stopBits: 1 | 2;
    parity: "none" | "even" | "odd";
    timeout: number;
}
export declare class ModbusRtuDriver implements ModbusDriver {
    private readonly connection;
    private client;
    constructor(connection: ModbusConnectionConfig);
    connect(): Promise<void>;
    disconnect(): Promise<void>;
    isOpen(): boolean;
    flashRelayOn(slaveId: number, address: number, durationMs: number): Promise<void>;
    readCoils(slaveId: number, address: number, length: number): Promise<boolean[]>;
    readDiscreteInputs(slaveId: number, address: number, length: number): Promise<boolean[]>;
    turnAllRelaysOff(slaveId: number): Promise<void>;
    private getClient;
    private getWaveshareClient;
}
export {};
