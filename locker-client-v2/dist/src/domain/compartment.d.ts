export type CompartmentNumber = number;
export type SlaveId = number;
export type RelayAddress = number;
export interface CompartmentTarget {
    compartmentNumber: CompartmentNumber;
    slaveId: SlaveId;
    relayAddress: RelayAddress;
}
export type DoorState = "open" | "closed" | "unknown";
export interface CompartmentConfig {
    compartment_number: number;
    slaveId: number;
    address: number;
}
export declare const MIN_FLASH_DURATION_MS = 100;
export declare const MAX_FLASH_DURATION_MS = 500;
export declare const DEFAULT_FLASH_DURATION_MS = 200;
export declare const FLASH_DURATION_STEP_MS = 100;
export declare function normalizeFlashDurationMs(configured: number | undefined): number;
export declare function toFlashDurationSteps(durationMs: number): number;
