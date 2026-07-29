import type { CompartmentTarget, DoorState } from '../../src/domain/compartment';
import type { LockerBusPort } from '../../src/ports/locker-bus.port';

export class FakeLockerBus implements LockerBusPort {
  readonly flashCalls: Array<{
    target: CompartmentTarget;
    durationMs: number;
  }> = [];
  readonly writeCoilCalls: Array<{
    slaveId: number;
    address: number;
    value: boolean;
  }> = [];
  readonly turnAllOffCalls: number[] = [];
  readonly doorBatchReads: Array<{ slaveId: number; startAddress: number; length: number }> = [];
  private relayStates = new Map<string, boolean>();
  private doorStates = new Map<number, DoorState[]>();
  private connected = true;
  private slaveIds: number[];

  constructor(slaveIds: number[] = [1]) {
    this.slaveIds = slaveIds;
  }

  async connect(): Promise<void> {
    this.connected = true;
  }

  async disconnect(): Promise<void> {
    this.connected = false;
  }

  getConnectionState() {
    return this.connected ? ('connected' as const) : ('disconnected' as const);
  }

  async ensureConnected(): Promise<boolean> {
    return this.connected;
  }

  async flashRelay(target: CompartmentTarget, durationMs: number): Promise<void> {
    this.flashCalls.push({ target, durationMs });
    this.relayStates.set(this.key(target), true);
  }

  recordWriteCoil(slaveId: number, address: number, value: boolean): void {
    this.writeCoilCalls.push({ slaveId, address, value });
  }

  async readRelayState(target: CompartmentTarget): Promise<boolean> {
    return this.relayStates.get(this.key(target)) ?? false;
  }

  async readDoorSensors(
    slaveId: number,
    startAddress: number,
    length: number,
  ): Promise<DoorState[]> {
    this.doorBatchReads.push({ slaveId, startAddress, length });
    const states =
      this.doorStates.get(slaveId) ?? Array.from({ length: 8 }, () => 'closed' as const);
    return states.slice(startAddress, startAddress + length);
  }

  async turnAllRelaysOff(slaveId: number): Promise<void> {
    this.turnAllOffCalls.push(slaveId);
  }

  getConfiguredSlaveIds(): number[] {
    return [...this.slaveIds];
  }

  reloadRuntimeConfig = async (): Promise<void> => undefined;

  setRelayState(target: CompartmentTarget, on: boolean): void {
    this.relayStates.set(this.key(target), on);
  }

  setDoorBatchStates(slaveId: number, states: DoorState[]): void {
    this.doorStates.set(slaveId, [...states]);
  }

  setDoorState(target: CompartmentTarget, state: DoorState): void {
    const states = this.doorStates.get(target.slaveId) ?? Array.from({ length: 8 }, () => 'closed');
    states[target.relayAddress] = state;
    this.doorStates.set(target.slaveId, states);
  }

  private key(target: CompartmentTarget): string {
    return `${target.slaveId}:${target.relayAddress}`;
  }
}
