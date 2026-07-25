import type { CompartmentConfig } from '../../src/domain/compartment';
import { deriveConfiguredSlaveIds } from '../../src/domain/config';
import type { ConfigRepositoryPort } from '../../src/ports/config.port';

const DEFAULT_MQTT_TRANSPORT_SETTINGS = {
  clean: false,
  keepalive: 60,
  reconnectPeriod: 5000,
  connectTimeout: 30000,
  maxReconnectAttempts: 0,
} as const;

export function createTestConfigRepository(
  overrides: Partial<ConfigRepositoryPort> & {
    compartments?: CompartmentConfig[];
    heartbeatIntervalSeconds?: number;
  } = {},
): ConfigRepositoryPort {
  const { compartments, heartbeatIntervalSeconds = 15, ...portOverrides } = overrides;
  const baseConfig = {
    modbus: { port: '/dev/null', flashDurationMs: 200 },
    mqtt:
      heartbeatIntervalSeconds !== undefined
        ? { heartbeatInterval: heartbeatIntervalSeconds }
        : undefined,
    ...(compartments !== undefined ? { compartments } : {}),
  };

  return {
    load: () => baseConfig,
    reload: () => baseConfig,
    getCompartmentConfig: (n) => compartments?.find((c) => c.compartment_number === n) ?? null,
    getConfiguredSlaveIds: () => deriveConfiguredSlaveIds(compartments),
    getFlashDurationMs: () => 200,
    getHeartbeatIntervalSeconds: () => heartbeatIntervalSeconds,
    getMqttTransportSettings: () => ({ ...DEFAULT_MQTT_TRANSPORT_SETTINGS }),
    ...portOverrides,
  };
}
