import type { CompartmentTarget, DoorState } from '../domain/compartment';

export enum BusPriority {
  COMMAND = 4,
  SNAPSHOT = 3,
  POLL = 2,
  MAINTENANCE = 1,
}

export type ConnectionState = 'disconnected' | 'connecting' | 'connected';
export type UnlockFeedback = 'pulse_sent' | 'opened' | 'failed';

export interface LockerBusPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  getConnectionState(): ConnectionState;
  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T>;
  ensureConnected(): Promise<boolean>;
  reloadRuntimeConfig(): Promise<void>;
  flashRelay(target: CompartmentTarget, durationMs: number): Promise<UnlockFeedback>;
  readRelayState(target: CompartmentTarget): Promise<boolean>;
  readDoorSensors(slaveId: number, startAddress: number, length: number): Promise<DoorState[]>;
  initializeBoard(slaveId: number): Promise<void>;
  getConfiguredSlaveIds(): number[];
}

export interface BusOperationRecorder {
  recordFlashRelay(target: CompartmentTarget, durationMs: number): Promise<void>;
  recordWriteCoil?(slaveId: number, address: number, value: boolean): Promise<void>;
}
