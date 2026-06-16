export declare const FLASH_ON_BASE_ADDRESS = 512;
export declare const FLASH_OFF_BASE_ADDRESS = 1024;
export declare const ALL_RELAYS_ADDRESS = 255;
export interface WaveshareModbusClient {
    setID(slaveId: number): void;
    customFunction(functionCode: number, data: Buffer): Promise<unknown>;
}
export declare function flashRelayOn(client: WaveshareModbusClient, slaveId: number, address: number, durationMs: number): Promise<void>;
export declare function turnAllRelaysOff(client: WaveshareModbusClient, slaveId: number): Promise<void>;
export declare function resolveFlashDurationMs(configured?: number): number;
