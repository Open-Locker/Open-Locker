import fs from 'fs';
import { load } from 'js-yaml';
import type { LockerConfig, RuntimeConfigOverlay } from '../../domain/config';
import { normalizeFlashDurationMs } from '../../domain/compartment';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import type { MqttTransportSettings } from '../../ports/mqtt.port';
import { CONFIG_FILE } from '../../infrastructure/paths';
import { FileRuntimeOverlayStore } from './runtime-overlay.store';

function mergeRuntimeConfig(
  base: LockerConfig,
  overlay: RuntimeConfigOverlay | null,
): LockerConfig {
  if (!overlay) {
    return base;
  }
  return {
    ...base,
    mqtt: {
      ...base.mqtt,
      heartbeatInterval: overlay.mqtt?.heartbeatInterval ?? base.mqtt?.heartbeatInterval,
    },
    compartments: overlay.compartments ?? base.compartments,
  };
}

export class YamlConfigRepository implements ConfigRepositoryPort {
  private config: LockerConfig | null = null;
  private explicitRuntimeCompartments = false;

  constructor(private readonly overlayStore = new FileRuntimeOverlayStore()) {}

  load(): LockerConfig {
    if (this.config) {
      return this.config;
    }

    if (!fs.existsSync(CONFIG_FILE)) {
      throw new Error(`Configuration file not found: ${CONFIG_FILE}`);
    }

    const parsed = (load(fs.readFileSync(CONFIG_FILE, 'utf8')) as LockerConfig) ?? {};
    parsed.mqtt = parsed.mqtt ?? {};

    if (!parsed.modbus?.port) {
      throw new Error('modbus.port is required');
    }

    normalizeFlashDurationMs(parsed.modbus.flashDurationMs);

    const overlay = this.overlayStore.load();
    this.explicitRuntimeCompartments = overlay?.compartments !== undefined;
    this.config = mergeRuntimeConfig(parsed, overlay);
    return this.config;
  }

  reload(): LockerConfig {
    this.config = null;
    this.explicitRuntimeCompartments = false;
    return this.load();
  }

  getCompartmentConfig(compartmentNumber: number) {
    const config = this.load();
    return config.compartments?.find((c) => c.compartment_number === compartmentNumber) ?? null;
  }

  hasExplicitRuntimeCompartments(): boolean {
    this.load();
    return this.explicitRuntimeCompartments;
  }

  getFlashDurationMs(): number {
    return normalizeFlashDurationMs(this.load().modbus.flashDurationMs);
  }

  getHeartbeatIntervalSeconds(): number {
    return this.load().mqtt?.heartbeatInterval ?? 15;
  }

  getMqttTransportSettings(): MqttTransportSettings {
    const m = this.load().mqtt ?? {};
    return {
      clean: m.cleanSession ?? false,
      keepalive: m.keepaliveSeconds ?? 60,
      reconnectPeriod: m.reconnectPeriodMs ?? 5000,
      connectTimeout: m.connectTimeoutMs ?? 30000,
      maxReconnectAttempts: m.maxReconnectAttempts ?? 0,
    };
  }

  getConfiguredSlaveIds(): number[] {
    const config = this.load();
    const ids = new Set<number>();
    for (const c of config.compartments ?? []) {
      ids.add(c.slaveId);
    }
    if (ids.size === 0) {
      ids.add(1);
    }
    return [...ids];
  }
}
