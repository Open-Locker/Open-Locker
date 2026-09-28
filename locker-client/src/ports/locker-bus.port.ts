import type {
  BoardAddress,
  CompartmentAddress,
  CompartmentTarget,
  DoorState,
} from '../domain/compartment';

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

/** Door state observed directly after actuation, when the controller reports one. */
export interface UnlockObservation {
  doorState?: DoorState;
}

/**
 * Protocol-neutral locker controller boundary (ADR-0067). Board-specific
 * behaviour — wire protocol, channel numbering, feedback polarity — stays in the
 * adapter.
 */
export interface LockerBusPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  getConnectionState(): ConnectionState;
  runExclusive<T>(operation: (bus: LockerBusPort) => Promise<T>): Promise<T>;
  ensureConnected(): Promise<boolean>;
  reloadRuntimeConfig(): Promise<void>;
  /**
   * Rejects with `UnlockNotSentError` when no request left the client, and with
   * `UnlockOutcomeUnknownError` when the lock may have moved.
   */
  unlockCompartment(target: CompartmentTarget): Promise<UnlockObservation>;
  /**
   * States for `addresses` on one board, in the same order. Rejects when the
   * board cannot be read, with `BoardNotRespondingError` when it stays silent.
   */
  readCompartmentStates(
    boardAddress: BoardAddress,
    addresses: readonly CompartmentAddress[],
  ): Promise<DoorState[]>;
  getConfiguredBoardAddresses(): BoardAddress[];
}
