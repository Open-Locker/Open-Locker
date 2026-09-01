import type { CompartmentConfig } from '../domain/compartment';
import type { EffectiveLockerConfig, RuntimeConfigOverlay } from '../domain/config';

export interface ConfigRepositoryPort {
  load(): EffectiveLockerConfig;
  reload(): EffectiveLockerConfig;
  getCompartmentConfig(compartmentNumber: number): CompartmentConfig | null;
  getConfiguredSlaveIds(): number[];
  getFlashDurationMs(): number;
  getHeartbeatIntervalSeconds(): number;
  getMqttTransportSettings(): import('./mqtt.port').MqttTransportSettings;
}

export interface RuntimeOverlayStorePort {
  load(): RuntimeConfigOverlay | null;
  save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay;
  clear(): void;
}

/**
 * Broker credentials plus the locker this device is authorised for. The username
 * authenticates and nothing more: every locker topic is built from `lockerUuid`.
 * They were the same value before per-provisioning identities, which is why files
 * written by an older client carry only the username.
 */
export interface DeviceCredentials {
  username: string;
  password: string;
  lockerUuid: string;
}

export interface CredentialStorePort {
  getCredentials(): DeviceCredentials | null;
  saveCredentials(credentials: DeviceCredentials): void;
  isProvisioned(): boolean;
}

export interface ClockPort {
  nowIso(): string;
}

export interface SchedulerPort {
  scheduleAfter(delayMs: number, fn: () => Promise<void>): () => void;
  cancelAll(): void;
}
