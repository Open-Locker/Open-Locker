import type { CompartmentTarget, DoorState } from '../../domain/compartment';
import type {
  ConnectionState,
  LockerBusPort,
  UnlockObservation,
} from '../../ports/locker-bus.port';

/**
 * Fake hardware for the fleet simulator.
 *
 * Sibling of `Rs485LockBoardBusActor`: same port, no serial line. Door state
 * lives in memory, keyed by the `boardAddress:address` pair the real driver
 * addresses boards with, so the use cases above the port cannot tell the
 * difference.
 *
 * Door behaviour mirrors a real locker: an unlock pops the door open, and it
 * stays open until something closes it — a scripted scenario step or a manual
 * toggle. Nothing closes a door on its own, because real doors don't. Like the
 * RS485 board, an unlock reports the door state it observed.
 */
export interface InMemoryLockerBusOptions {
  /** Boards this device answers for; mirrors the configured board addresses. */
  boardAddresses: number[];
  /** Door states at startup, keyed by `boardAddress:address`. Defaults to closed. */
  initialDoorStates?: Map<string, DoorState>;
  /**
   * Compartments whose door will not open when unlocked, keyed by
   * `boardAddress:address`. Reproduces a jam, blocked door, or failed latch —
   * the case door-open detection exists to catch.
   */
  jammedTargets?: Set<string>;
  /** Simulated round-trip delay per bus operation, in milliseconds. */
  latencyMs?: number;
}

export function busTargetKey(boardAddress: number, address: number): string {
  return `${boardAddress}:${address}`;
}

export class InMemoryLockerBus implements LockerBusPort {
  private connectionState: ConnectionState = 'disconnected';

  private readonly doorStates = new Map<string, DoorState>();

  private readonly jammedTargets = new Set<string>();

  private readonly boardAddresses: number[];

  private readonly latencyMs: number;

  constructor(options: InMemoryLockerBusOptions) {
    this.boardAddresses = [...options.boardAddresses];
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
   * On real hardware an unlock releases the latch and the door springs open, so
   * the simulated door flips to `open` and stays there.
   *
   * A jammed compartment unlocks normally but its door does not move, which is
   * exactly what a real jam, blockage, or worn latch looks like from the bus.
   */
  async unlockCompartment(target: CompartmentTarget): Promise<UnlockObservation> {
    if (this.connectionState !== 'connected') {
      await this.connect();
    }

    await this.delay();

    const key = busTargetKey(target.boardAddress, target.address);
    if (!this.jammedTargets.has(key)) {
      this.doorStates.set(key, 'open');
    }

    return { doorState: this.doorStates.get(key) ?? 'closed' };
  }

  async readCompartmentStates(
    boardAddress: number,
    addresses: readonly number[],
  ): Promise<DoorState[]> {
    await this.delay();

    return addresses.map(
      (address) => this.doorStates.get(busTargetKey(boardAddress, address)) ?? 'closed',
    );
  }

  getConfiguredBoardAddresses(): number[] {
    return [...this.boardAddresses];
  }

  // --- simulator-only controls, not part of LockerBusPort ---

  /** Scripted or manual door change; the next poll publishes a fresh snapshot. */
  setDoorState(boardAddress: number, address: number, state: DoorState): void {
    this.doorStates.set(busTargetKey(boardAddress, address), state);
  }

  getDoorState(boardAddress: number, address: number): DoorState {
    return this.doorStates.get(busTargetKey(boardAddress, address)) ?? 'closed';
  }

  /** Jam or unjam a compartment at runtime, from the interactive console. */
  setJammed(boardAddress: number, address: number, jammed: boolean): void {
    const key = busTargetKey(boardAddress, address);

    if (jammed) {
      this.jammedTargets.add(key);

      return;
    }

    this.jammedTargets.delete(key);
  }

  isJammed(boardAddress: number, address: number): boolean {
    return this.jammedTargets.has(busTargetKey(boardAddress, address));
  }

  private delay(): Promise<void> {
    if (this.latencyMs <= 0) {
      return Promise.resolve();
    }

    return new Promise((resolve) => setTimeout(resolve, this.latencyMs));
  }
}
