import type { CompartmentTarget, DoorState } from '../../src/domain/compartment';
import type { LockerBusPort, UnlockObservation } from '../../src/ports/locker-bus.port';

export class FakeLockerBus implements LockerBusPort {
  readonly unlockCalls: CompartmentTarget[] = [];
  readonly doorReads: Array<{ boardAddress: number; addresses: number[] }> = [];
  /** What the next unlock reports; no observation by default, like a controller without feedback. */
  unlockObservation: UnlockObservation = {};
  private doorStates = new Map<number, DoorState[]>();
  private connected = true;
  private boardAddresses: number[];

  constructor(boardAddresses: number[] = [1]) {
    this.boardAddresses = boardAddresses;
  }

  async connect(): Promise<void> {
    this.connected = true;
  }

  async disconnect(): Promise<void> {
    this.connected = false;
  }

  /** Set to mimic a bus whose reconnect cycle was spent. */
  unreachable = false;

  getConnectionState() {
    if (this.unreachable) {
      return 'unreachable' as const;
    }

    return this.connected ? ('connected' as const) : ('disconnected' as const);
  }

  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T> {
    return operation(this);
  }

  async ensureConnected(): Promise<boolean> {
    return this.connected;
  }

  async unlockCompartment(target: CompartmentTarget): Promise<UnlockObservation> {
    this.unlockCalls.push(target);
    return this.unlockObservation;
  }

  async readCompartmentStates(
    boardAddress: number,
    addresses: readonly number[],
  ): Promise<DoorState[]> {
    this.doorReads.push({ boardAddress, addresses: [...addresses] });
    const states = this.doorStates.get(boardAddress) ?? [];
    return addresses.map((address) => states[address] ?? 'closed');
  }

  getConfiguredBoardAddresses(): number[] {
    return [...this.boardAddresses];
  }

  reloadRuntimeConfig = async (): Promise<void> => undefined;

  setBoardStates(boardAddress: number, states: DoorState[]): void {
    this.doorStates.set(boardAddress, [...states]);
  }

  setDoorState(target: CompartmentTarget, state: DoorState): void {
    const states = this.doorStates.get(target.boardAddress) ?? [];
    states[target.address] = state;
    this.doorStates.set(target.boardAddress, states);
  }
}
