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

export const MIN_FLASH_DURATION_MS = 100;
export const MAX_FLASH_DURATION_MS = 500;
export const DEFAULT_FLASH_DURATION_MS = 200;
export const FLASH_DURATION_STEP_MS = 100;

export function normalizeFlashDurationMs(
  configured: number | undefined,
): number {
  const value = configured ?? DEFAULT_FLASH_DURATION_MS;

  if (!Number.isFinite(value) || value < MIN_FLASH_DURATION_MS) {
    throw new Error(
      `flashDurationMs must be at least ${MIN_FLASH_DURATION_MS}ms`,
    );
  }

  if (value > MAX_FLASH_DURATION_MS) {
    throw new Error(
      `flashDurationMs must not exceed ${MAX_FLASH_DURATION_MS}ms`,
    );
  }

  return value;
}

export function toFlashDurationSteps(durationMs: number): number {
  const normalized = normalizeFlashDurationMs(durationMs);
  return Math.ceil(normalized / FLASH_DURATION_STEP_MS);
}
