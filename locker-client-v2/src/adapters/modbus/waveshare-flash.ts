import {
  DEFAULT_FLASH_DURATION_MS,
  toFlashDurationSteps,
} from "../../domain/compartment";

export const FLASH_ON_BASE_ADDRESS = 0x0200;
export const FLASH_OFF_BASE_ADDRESS = 0x0400;
export const ALL_RELAYS_ADDRESS = 0x00ff;

export interface WaveshareModbusClient {
  setID(slaveId: number): void;
  customFunction(functionCode: number, data: Buffer): Promise<unknown>;
}

export async function flashRelayOn(
  client: WaveshareModbusClient,
  slaveId: number,
  address: number,
  durationMs: number,
): Promise<void> {
  const flashAddress = FLASH_ON_BASE_ADDRESS + address;
  const steps = toFlashDurationSteps(durationMs);
  await writeRawFc5(client, slaveId, flashAddress, steps);
}

export async function turnAllRelaysOff(
  client: WaveshareModbusClient,
  slaveId: number,
): Promise<void> {
  await writeRawFc5(client, slaveId, ALL_RELAYS_ADDRESS, 0x0000);
}

async function writeRawFc5(
  client: WaveshareModbusClient,
  slaveId: number,
  dataAddress: number,
  value: number,
): Promise<void> {
  client.setID(slaveId);
  const payload = Buffer.from([
    (dataAddress >> 8) & 0xff,
    dataAddress & 0xff,
    (value >> 8) & 0xff,
    value & 0xff,
  ]);
  await client.customFunction(0x05, payload);
}

export function resolveFlashDurationMs(configured?: number): number {
  if (configured === undefined) {
    return DEFAULT_FLASH_DURATION_MS;
  }
  return configured;
}
