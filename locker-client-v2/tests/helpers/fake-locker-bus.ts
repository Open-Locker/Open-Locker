import type { CompartmentTarget, DoorState } from "../../src/domain/compartment";
import type { LockerBusPort } from "../../src/ports/locker-bus.port";

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
  private relayStates = new Map<string, boolean>();
  private doorStates = new Map<string, DoorState>();
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
    return this.connected ? ("connected" as const) : ("disconnected" as const);
  }

  async ensureConnected(): Promise<boolean> {
    return this.connected;
  }

  async flashRelay(
    target: CompartmentTarget,
    durationMs: number,
  ): Promise<void> {
    this.flashCalls.push({ target, durationMs });
    this.relayStates.set(this.key(target), true);
  }

  recordWriteCoil(slaveId: number, address: number, value: boolean): void {
    this.writeCoilCalls.push({ slaveId, address, value });
  }

  async readRelayState(target: CompartmentTarget): Promise<boolean> {
    return this.relayStates.get(this.key(target)) ?? false;
  }

  async readDoorSensor(target: CompartmentTarget): Promise<DoorState> {
    return this.doorStates.get(this.key(target)) ?? "closed";
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

  private key(target: CompartmentTarget): string {
    return `${target.slaveId}:${target.relayAddress}`;
  }
}
