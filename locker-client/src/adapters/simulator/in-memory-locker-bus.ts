import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type { ConnectionState, LockerBusPort } from '../../ports/locker-bus.port';

/**
 * Fake hardware for the fleet simulator.
 *
 * Sibling of `WaveshareModbusBusActor`: same port, no Modbus. Relay and door
 * state live in memory, keyed by the `slaveId:address` pair the real driver
 * addresses boards with, so the use cases above the port cannot tell the
 * difference.
 *
 * Door behaviour mirrors a real locker: flashing a relay pops the door open,
 * and it stays open until something closes it — a scripted scenario step or a
 * manual toggle. Nothing closes a door on its own, because real doors don't.
 */
export interface InMemoryLockerBusOptions {
  /** Boards this device answers for; mirrors the configured Modbus slave ids. */
  slaveIds: number[];
  /** Door states at startup, keyed by `slaveId:address`. Defaults to closed. */
  initialDoorStates?: Map<string, DoorState>;
  /**
   * Compartments whose door will not open when the relay fires, keyed by
   * `slaveId:address`. Reproduces a jam, blocked door, or failed latch — the
   * case door-open detection exists to catch.
   */
  jammedTargets?: Set<string>;
  /** Simulated round-trip delay per bus operation, in milliseconds. */
  latencyMs?: number;
}

export function busTargetKey(slaveId: number, address: number): string {
  return `${slaveId}:${address}`;
}

export class InMemoryLockerBus implements LockerBusPort {
  private connectionState: ConnectionState = 'disconnected';

  private readonly doorStates = new Map<string, DoorState>();

  private readonly relayStates = new Map<string, boolean>();

  private readonly flashTimers = new Map<string, NodeJS.Timeout>();

  private readonly jammedTargets = new Set<string>();

  private readonly slaveIds: number[];

  private readonly latencyMs: number;

  constructor(options: InMemoryLockerBusOptions) {
    this.slaveIds = [...options.slaveIds];
    this.latencyMs = options.latencyMs ?? 0;

    for (const [key, state] of options.initialDoorStates ?? []) {
      this.doorStates.set(key, state);
    }

    for (const key of options.jammedTargets ?? []) {
      this.jammedTargets.add(key);
    }
  }

  async connect(): Promise<void> {
    this.connectionState = 'connecting';
    await this.delay();
    this.connectionState = 'connected';
  }

  async disconnect(): Promise<void> {
    for (const timer of this.flashTimers.values()) {
      clearTimeout(timer);
    }
    this.flashTimers.clear();
    this.connectionState = 'disconnected';
  }

  getConnectionState(): ConnectionState {
    return this.connectionState;
  }

  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T> {
    return operation(this);
  }

  async ensureConnected(): Promise<boolean> {
    if (this.connectionState !== 'connected') {
      await this.connect();
    }

    return true;
  }

  async reloadRuntimeConfig(): Promise<void> {
    // Nothing to reload: there is no serial port to reopen.
  }

  /**
   * Pulse a relay. On real hardware the pulse releases the latch and the door
   * springs open, so the simulated door flips to `open` and stays there.
   *
   * A jammed compartment pulses normally but its door does not move, which is
   * exactly what a real jam, blockage, or worn latch looks like from the bus.
   */
  async flashRelay(target: CompartmentTarget, durationMs: number): Promise<'pulse_sent'> {
    await this.delay();

    const key = busTargetKey(target.slaveId, target.relayAddress);
    this.relayStates.set(key, true);

    const existingTimer = this.flashTimers.get(key);
    if (existingTimer) {
      clearTimeout(existingTimer);
    }

    this.flashTimers.set(
      key,
      setTimeout(() => {
        this.relayStates.set(key, false);
        this.flashTimers.delete(key);
      }, durationMs),
    );

    if (!this.jammedTargets.has(key)) {
      this.doorStates.set(key, 'open');
    }
    return 'pulse_sent';
  }

  async readRelayState(target: CompartmentTarget): Promise<boolean> {
    await this.delay();

    return this.relayStates.get(busTargetKey(target.slaveId, target.relayAddress)) ?? false;
  }

  /**
   * Contiguous block read, matching the real driver: index `i` of the result is
   * the sensor at `startAddress + i`.
   */
  async readDoorSensors(
    slaveId: number,
    startAddress: number,
    length: number,
  ): Promise<DoorState[]> {
    await this.delay();

    return Array.from(
      { length },
      (_unused, offset) =>
        this.doorStates.get(busTargetKey(slaveId, startAddress + offset)) ?? 'closed',
    );
  }

  async initializeBoard(slaveId: number): Promise<void> {
    await this.delay();

    // Safe to iterate live: only existing keys are reassigned, none added or removed.
    for (const key of this.relayStates.keys()) {
      if (key.startsWith(`${slaveId}:`)) {
        this.relayStates.set(key, false);
      }
    }
  }

  /** Simulator compatibility helper for scenarios that explicitly test relay clearing. */
  async turnAllRelaysOff(slaveId: number): Promise<void> {
    await this.initializeBoard(slaveId);
  }

  getConfiguredSlaveIds(): number[] {
    return [...this.slaveIds];
  }

  // --- simulator-only controls, not part of LockerBusPort ---

  /** Scripted or manual door change; the next poll publishes a fresh snapshot. */
  setDoorState(slaveId: number, address: number, state: DoorState): void {
    this.doorStates.set(busTargetKey(slaveId, address), state);
  }

  getDoorState(slaveId: number, address: number): DoorState {
    return this.doorStates.get(busTargetKey(slaveId, address)) ?? 'closed';
  }

  /** Jam or unjam a compartment at runtime, from the interactive console. */
  setJammed(slaveId: number, address: number, jammed: boolean): void {
    const key = busTargetKey(slaveId, address);

    if (jammed) {
      this.jammedTargets.add(key);

      return;
    }

    this.jammedTargets.delete(key);
  }

  isJammed(slaveId: number, address: number): boolean {
    return this.jammedTargets.has(busTargetKey(slaveId, address));
  }

  private delay(): Promise<void> {
    if (this.latencyMs <= 0) {
      return Promise.resolve();
    }

    return new Promise((resolve) => setTimeout(resolve, this.latencyMs));
  }
}
