import type { CompartmentConfig } from './compartment';

export interface MqttTransportConfig {
  cleanSession?: boolean;
  keepaliveSeconds?: number;
  reconnectPeriodMs?: number;
  connectTimeoutMs?: number;
  maxReconnectAttempts?: number;
}

export interface MqttRuntimeConfig extends MqttTransportConfig {
  heartbeatInterval?: number;
}

/** The serial device is the only operator setting; the adapter owns the rest (ADR-0067). */
export interface SerialConfig {
  port: string;
}

export type AdapterType = 'rs485_lock_board';
export type FeedbackType = 'door_closing' | 'door_opening';

export interface HardwareProfile {
  adapterType: AdapterType;
  feedbackType: FeedbackType;
}

/** Operator-managed settings loaded from locker-config.yml. */
export interface BaseLockerConfig {
  mqtt?: MqttTransportConfig;
  serial: SerialConfig;
}

/** Effective runtime configuration: base YAML merged with server-managed overlay. */
export interface EffectiveLockerConfig {
  mqtt?: MqttRuntimeConfig;
  serial: SerialConfig;
  hardwareProfile?: HardwareProfile;
  compartments?: CompartmentConfig[];
}

export interface RuntimeConfigOverlay {
  mqtt?: {
    heartbeatInterval?: number;
  };
  compartments?: CompartmentConfig[];
  hardwareProfile?: HardwareProfile;
  appliedConfigHash?: string;
  updatedAt?: string;
}

export function deriveConfiguredBoardAddresses(
  compartments: CompartmentConfig[] | undefined,
): number[] {
  if (compartments === undefined) {
    return [];
  }

  const addresses = new Set<number>();
  for (const compartment of compartments) {
    addresses.add(compartment.slaveId);
  }
  return [...addresses];
}

/** Zero-based channel address encodable as a non-zero one-byte wire channel (1..255). */
export function isWireEncodableChannelAddress(address: number): boolean {
  return Number.isInteger(address) && address >= 0 && address <= 254;
}
