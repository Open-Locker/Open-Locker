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

export interface ModbusConfig {
  port: string;
  flashDurationMs?: number;
  baudRate?: number;
  dataBits?: 7 | 8;
  stopBits?: 1 | 2;
  parity?: 'none' | 'even' | 'odd';
  timeout?: number;
  /**
   * How long a spent reconnect cycle waits before another is allowed. Bounds the
   * retry rate against hardware that is genuinely gone, without letting a spent
   * budget outlive the outage that spent it.
   */
  reconnectCooldownSeconds?: number;
}

/** Operator-managed settings loaded from locker-config.yml. */
export interface BaseLockerConfig {
  mqtt?: MqttTransportConfig;
  modbus: ModbusConfig;
}

/** Effective runtime configuration: base YAML merged with server-managed overlay. */
export interface EffectiveLockerConfig {
  mqtt?: MqttRuntimeConfig;
  modbus: ModbusConfig;
  compartments?: CompartmentConfig[];
}

export interface RuntimeConfigOverlay {
  mqtt?: {
    heartbeatInterval?: number;
  };
  compartments?: CompartmentConfig[];
  appliedConfigHash?: string;
  updatedAt?: string;
}

export function deriveConfiguredSlaveIds(compartments: CompartmentConfig[] | undefined): number[] {
  if (compartments === undefined) {
    return [];
  }

  const ids = new Set<number>();
  for (const compartment of compartments) {
    ids.add(compartment.slaveId);
  }
  return [...ids];
}
