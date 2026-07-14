import fs from 'fs';
import { load } from 'js-yaml';
import type {
  BaseLockerConfig,
  EffectiveLockerConfig,
  RuntimeConfigOverlay,
} from '../../domain/config';
import { deriveConfiguredSlaveIds } from '../../domain/config';
import { normalizeFlashDurationMs } from '../../domain/compartment';
import type { ConfigRepositoryPort } from '../../ports/config.port';
import type { MqttTransportSettings } from '../../ports/mqtt.port';
import { CONFIG_FILE } from '../../infrastructure/paths';
import { FileRuntimeOverlayStore } from './runtime-overlay.store';

function mergeRuntimeConfig(
  base: BaseLockerConfig,
  overlay: RuntimeConfigOverlay | null,
): EffectiveLockerConfig {
  const effective: EffectiveLockerConfig = {
    modbus: base.modbus,
    mqtt: base.mqtt ? { ...base.mqtt } : undefined,
  };

  if (overlay?.mqtt?.heartbeatInterval !== undefined) {
    effective.mqtt = {
      ...effective.mqtt,
      heartbeatInterval: overlay.mqtt.heartbeatInterval,
    };
  }

  if (overlay?.compartments !== undefined) {
    effective.compartments = overlay.compartments;
  }

  return effective;
}

function parseBaseConfig(raw: unknown): BaseLockerConfig {
  const parsed = (raw as Record<string, unknown>) ?? {};
  const mqtt = parsed.mqtt as BaseLockerConfig['mqtt'] | undefined;

  return {
    modbus: parsed.modbus as BaseLockerConfig['modbus'],
    mqtt: mqtt
      ? {
          cleanSession: mqtt.cleanSession,
          keepaliveSeconds: mqtt.keepaliveSeconds,
          reconnectPeriodMs: mqtt.reconnectPeriodMs,
          connectTimeoutMs: mqtt.connectTimeoutMs,
          maxReconnectAttempts: mqtt.maxReconnectAttempts,
        }
      : undefined,
  };
}

export class YamlConfigRepository implements ConfigRepositoryPort {
  private config: EffectiveLockerConfig | null = null;

  constructor(
    private readonly overlayStore = new FileRuntimeOverlayStore(),
    private readonly configFilePath: string = CONFIG_FILE,
  ) {}

  load(): EffectiveLockerConfig {
    if (this.config) {
      return this.config;
    }

    if (!fs.existsSync(this.configFilePath)) {
      throw new Error(`Configuration file not found: ${this.configFilePath}`);
    }

    const base = parseBaseConfig(load(fs.readFileSync(this.configFilePath, 'utf8')));
    base.mqtt = base.mqtt ?? {};

    if (!base.modbus?.port) {
      throw new Error('modbus.port is required');
    }

    normalizeFlashDurationMs(base.modbus.flashDurationMs);

    const overlay = this.overlayStore.load();
    this.config = mergeRuntimeConfig(base, overlay);
    return this.config;
  }

  reload(): EffectiveLockerConfig {
    this.config = null;
    return this.load();
  }

  getCompartmentConfig(compartmentNumber: number) {
    const config = this.load();
    return config.compartments?.find((c) => c.compartment_number === compartmentNumber) ?? null;
  }

  getConfiguredSlaveIds(): number[] {
    return deriveConfiguredSlaveIds(this.load().compartments);
  }

  getFlashDurationMs(): number {
    return normalizeFlashDurationMs(this.load().modbus.flashDurationMs);
  }

  getHeartbeatIntervalSeconds(): number {
    return this.load().mqtt?.heartbeatInterval ?? 15;
  }

  getMqttTransportSettings(): MqttTransportSettings {
    const mqtt = this.load().mqtt ?? {};
    return {
      clean: mqtt.cleanSession ?? false,
      keepalive: mqtt.keepaliveSeconds ?? 60,
      reconnectPeriod: mqtt.reconnectPeriodMs ?? 5000,
      connectTimeout: mqtt.connectTimeoutMs ?? 30000,
      maxReconnectAttempts: mqtt.maxReconnectAttempts ?? 0,
    };
  }
}
