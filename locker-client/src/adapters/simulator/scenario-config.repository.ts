import type { CompartmentConfig } from '../../domain/compartment';
import { normalizeFlashDurationMs } from '../../domain/compartment';
import { deriveConfiguredSlaveIds, type EffectiveLockerConfig } from '../../domain/config';
import type { ConfigRepositoryPort, RuntimeOverlayStorePort } from '../../ports/config.port';
import type { MqttTransportSettings } from '../../ports/mqtt.port';
import type { SimulatorBankScenario } from './scenario';

/**
 * Config for one simulated device: the scenario file plays the role
 * `locker-config.yml` plus the runtime overlay play in production.
 *
 * Production is runtime-only for compartments — the mapping arrives
 * via `apply_config` and lands in the overlay. The simulator seeds the mapping
 * from the scenario so a device is useful the moment it boots, while an
 * `apply_config` command still overrides it exactly as on real hardware,
 * because the overlay is consulted first.
 */
export class ScenarioConfigRepository implements ConfigRepositoryPort {
  private cached: EffectiveLockerConfig | null = null;

  constructor(
    private readonly bank: SimulatorBankScenario,
    private readonly overlayStore: RuntimeOverlayStorePort,
    private readonly transportSettings: MqttTransportSettings = DEFAULT_SIMULATOR_TRANSPORT,
  ) {}

  load(): EffectiveLockerConfig {
    if (this.cached) {
      return this.cached;
    }

    const overlay = this.overlayStore.load();

    this.cached = {
      // `port` is required by the shared config shape but never opened: the
      // simulator's bus is in memory. The value is deliberately not a device
      // path so a misconfigured simulator cannot touch a real serial port.
      modbus: {
        port: 'simulated',
        flashDurationMs: this.bank.flash_duration_ms,
      },
      mqtt: {
        heartbeatInterval: overlay?.mqtt?.heartbeatInterval ?? this.bank.heartbeat_interval_seconds,
      },
      compartments: overlay?.compartments ?? this.scenarioCompartments(),
    };

    return this.cached;
  }

  reload(): EffectiveLockerConfig {
    this.cached = null;

    return this.load();
  }

  getCompartmentConfig(compartmentNumber: number): CompartmentConfig | null {
    return (
      this.load().compartments?.find(
        (compartment) => compartment.compartment_number === compartmentNumber,
      ) ?? null
    );
  }

  getConfiguredSlaveIds(): number[] {
    return deriveConfiguredSlaveIds(this.load().compartments);
  }

  getFlashDurationMs(): number {
    return normalizeFlashDurationMs(this.load().modbus.flashDurationMs);
  }

  getHeartbeatIntervalSeconds(): number {
    return this.load().mqtt?.heartbeatInterval ?? this.bank.heartbeat_interval_seconds;
  }

  getMqttTransportSettings(): MqttTransportSettings {
    return { ...this.transportSettings };
  }

  private scenarioCompartments(): CompartmentConfig[] {
    return this.bank.compartments.map((compartment) => ({
      compartment_number: compartment.compartment_number,
      slaveId: compartment.slaveId,
      address: compartment.address,
    }));
  }
}

/**
 * `clean: true` is the one deliberate divergence from the production default.
 * A simulated device is disposable; resuming a persistent broker session across
 * runs would replay commands aimed at a previous incarnation.
 */
const DEFAULT_SIMULATOR_TRANSPORT: MqttTransportSettings = {
  clean: true,
  keepalive: 60,
  reconnectPeriod: 5000,
  connectTimeout: 30000,
  maxReconnectAttempts: 0,
};
