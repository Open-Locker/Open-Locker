import type { CompartmentConfig } from '../../src/domain/compartment';
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
  } = {},
): ConfigRepositoryPort {
  const compartments = overrides.compartments;
  const baseConfig = {
    modbus: { port: '/dev/null', flashDurationMs: 200 },
    mqtt: { heartbeatInterval: 15 },
    ...(compartments ? { compartments } : {}),
  };

  return {
    load: () => baseConfig,
    reload: () => baseConfig,
    getCompartmentConfig: (n) => compartments?.find((c) => c.compartment_number === n) ?? null,
    hasExplicitRuntimeCompartments: () => compartments !== undefined,
    getFlashDurationMs: () => 200,
    getHeartbeatIntervalSeconds: () => 15,
    getMqttTransportSettings: () => ({ ...DEFAULT_MQTT_TRANSPORT_SETTINGS }),
    ...overrides,
  };
}
