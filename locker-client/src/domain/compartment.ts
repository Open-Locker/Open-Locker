export type CompartmentNumber = number;
export type BoardAddress = number;
export type CompartmentAddress = number;

export interface CompartmentTarget {
  compartmentNumber: CompartmentNumber;
  boardAddress: BoardAddress;
  address: CompartmentAddress;
}

export type DoorState = 'open' | 'closed' | 'unknown';

/** Wire shape from `apply_config`; `slaveId` is the board address (ADR-0067). */
export interface CompartmentConfig {
  compartment_number: number;
  slaveId: number;
  address: number;
}
