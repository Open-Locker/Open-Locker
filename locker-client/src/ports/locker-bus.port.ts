import type { CompartmentTarget, DoorState } from '../domain/compartment';

export enum BusPriority {
  COMMAND = 4,
  SNAPSHOT = 3,
  POLL = 2,
  MAINTENANCE = 1,
}

/**
 * `unreachable` means a reconnect cycle was spent without success: we tried, we
 * failed, and we have stopped trying *for now*. It is distinct from `connecting`,
 * which claims an attempt is in flight — a dead bus used to report that forever.
 *
 * Nothing on the wire changes: `modbus_connected` is derived from
 * `state === 'connected'` and was already false in both cases.
 */
export type ConnectionState = 'disconnected' | 'connecting' | 'connected' | 'unreachable';

export interface LockerBusPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  getConnectionState(): ConnectionState;
  ensureConnected(): Promise<boolean>;
  reloadRuntimeConfig(): Promise<void>;
  flashRelay(target: CompartmentTarget, durationMs: number): Promise<void>;
  readRelayState(target: CompartmentTarget): Promise<boolean>;
  readDoorSensors(slaveId: number, startAddress: number, length: number): Promise<DoorState[]>;
  turnAllRelaysOff(slaveId: number): Promise<void>;
  getConfiguredSlaveIds(): number[];
}

export interface BusOperationRecorder {
  recordFlashRelay(target: CompartmentTarget, durationMs: number): Promise<void>;
  recordWriteCoil?(slaveId: number, address: number, value: boolean): Promise<void>;
}
