import fs from 'fs';
import { load } from 'js-yaml';
import type {
  BaseLockerConfig,
  EffectiveLockerConfig,
  RuntimeConfigOverlay,
} from '../../domain/config';
import { deriveConfiguredSlaveIds } from '../../domain/config';
import { normalizeFlashDurationMs } from '../../domain/compartment';
import type { ConfigRepositoryPort, RuntimeOverlayStorePort } from '../../ports/config.port';
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

/**
 * Bounds a numeric setting read from the operator-managed config file.
 *
 * The MQTT path is already strict — `apply_config` is schema-validated before
 * it reaches the client. The file is not, and it is the one edited by hand on a
 * device with no feedback loop, so a typo there deserves the louder failure of
 * the two. `heartbeatInterval: 0` would otherwise reach `setInterval` and
 * publish as fast as the loop allows.
 *
 * Absent stays absent: the caller's default applies. Only a value that is
 * present and unusable throws.
 */
function requireBoundedSetting(
  value: number | undefined,
  name: string,
  min: number,
  max: number,
  alsoAllow?: number,
  requireInteger = false,
): void {
  if (value === undefined) {
    return;
  }

  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new Error(`${name} must be a number`);
  }

  if (requireInteger && !Number.isInteger(value)) {
    throw new Error(`${name} must be a whole number`);
  }

  if (alsoAllow !== undefined && value === alsoAllow) {
    return;
  }

  if (value < min || value > max) {
    const allowed = alsoAllow === undefined ? '' : ` (or ${alsoAllow})`;
    throw new Error(`${name} must be between ${min} and ${max}${allowed}`);
  }
}

/**
 * Rejects a setting the driver only accepts from a fixed set. Absent stays
 * absent, so the caller's default applies.
 */
function requireEnumSetting<T extends number | string>(
  value: T | undefined,
  name: string,
  allowed: readonly T[],
): void {
  if (value === undefined) {
    return;
  }

  if (!allowed.includes(value)) {
    throw new Error(`${name} must be one of ${allowed.join(', ')}`);
  }
}

export class YamlConfigRepository implements ConfigRepositoryPort {
  private config: EffectiveLockerConfig | null = null;

  constructor(
    private readonly overlayStore: RuntimeOverlayStorePort = new FileRuntimeOverlayStore(),
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

    // Timer and transport values, bounded at load so a bad file fails at
    // startup rather than becoming odd behaviour hours later.
    // Serial framing. `baudRate` is not just handed to the driver: it drives the
    // RTU inter-frame delay, so a bogus value degrades the pacing silently
    // rather than failing loudly.
    requireBoundedSetting(base.modbus.baudRate, 'modbus.baudRate', 1_200, 921_600);
    requireBoundedSetting(base.modbus.timeout, 'modbus.timeout', 50, 60_000);
    requireBoundedSetting(
      base.modbus.reconnectCooldownSeconds,
      'modbus.reconnectCooldownSeconds',
      5,
      3600,
    );
    requireEnumSetting(base.modbus.dataBits, 'modbus.dataBits', [7, 8]);
    requireEnumSetting(base.modbus.stopBits, 'modbus.stopBits', [1, 2]);
    requireEnumSetting(base.modbus.parity, 'modbus.parity', ['none', 'even', 'odd']);

    requireBoundedSetting(base.mqtt.keepaliveSeconds, 'mqtt.keepaliveSeconds', 5, 3600, 0);
    requireBoundedSetting(base.mqtt.reconnectPeriodMs, 'mqtt.reconnectPeriodMs', 500, 300_000, 0);
    requireBoundedSetting(base.mqtt.connectTimeoutMs, 'mqtt.connectTimeoutMs', 1_000, 120_000);
    requireBoundedSetting(
      base.mqtt.maxReconnectAttempts,
      'mqtt.maxReconnectAttempts',
      1,
      Number.MAX_SAFE_INTEGER,
      0,
    );

    const overlay = this.overlayStore.load();
    const effective = mergeRuntimeConfig(base, overlay);

    // The heartbeat interval reaches the effective config only through the
    // runtime overlay — `apply_config` validates it on the way in, but the
    // overlay is a file on disk and can be edited or corrupted after the fact.
    //
    // No upper bound: the inbound schema is `positive()` and the admin panel
    // sets only a minimum, so a ceiling here would reject a value the panel
    // legitimately offers — the command would be accepted, written, and then
    // fail on the reload it triggered.
    requireBoundedSetting(
      effective.mqtt?.heartbeatInterval,
      'mqtt.heartbeatInterval',
      1,
      Number.MAX_SAFE_INTEGER,
      undefined,
      // The inbound schema is `int()`; the file should not be looser than the
      // contract in the other direction either.
      true,
    );

    this.config = effective;
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
