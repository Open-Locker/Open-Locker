import {
  DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS,
  ModbusBusActor,
} from '../adapters/modbus/bus-actor';
import { ModbusRtuDriver } from '../adapters/modbus/modbus-rtu.driver';
import { YamlConfigRepository } from '../adapters/config/yaml-config.repository';
import { FileRuntimeOverlayStore } from '../adapters/config/runtime-overlay.store';
import { FileCredentialStore } from '../adapters/persistence/file-credential.store';
import { FileDedupStore } from '../adapters/mqtt/dedup-store';
import { MqttTransportAdapter } from '../adapters/mqtt/mqtt-transport.adapter';
import { OutboundMqttAdapter } from '../adapters/mqtt/outbound-mqtt.adapter';
import { InboundProtocolGuard } from '../adapters/mqtt/inbound-protocol-guard';
import { CommandDispatcher } from '../adapters/mqtt/command-dispatcher';
import { createOpenCompartmentHandler } from '../adapters/mqtt/handlers/open-compartment.handler';
import { createApplyConfigHandler } from '../adapters/mqtt/handlers/apply-config.handler';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../application/open-compartment';
import { ApplyConfigUseCase } from '../application/apply-config';
import { HeartbeatUseCase, PollCompartmentStateUseCase } from '../application/state-publishing';
import { RunAfterCompleteScheduler } from '../infrastructure/scheduler';
import {
  DEFAULT_MQTT_BROKER_URL,
  getOrCreateClientId,
  provisionDevice,
} from '../application/provision-device';
import { connectionLostWillOptions } from '../infrastructure/mqtt-will';
import { logger } from '../infrastructure/logging';
import { createWinstonLoggerPort } from '../infrastructure/winston-logger.adapter';
import { MQTT_CLIENT_ID_FILE } from '../infrastructure/paths';

export interface AppContext {
  shutdown(): Promise<void>;
}

export async function createApp(): Promise<AppContext> {
  const configRepo = new YamlConfigRepository(new FileRuntimeOverlayStore());
  const config = configRepo.load();
  const credentialStore = new FileCredentialStore();
  const dedupStore = new FileDedupStore();
  const transport = new MqttTransportAdapter(configRepo.getMqttTransportSettings());

  const driver = new ModbusRtuDriver({
    port: config.modbus.port,
    baudRate: config.modbus.baudRate ?? 9600,
    dataBits: config.modbus.dataBits ?? 8,
    stopBits: config.modbus.stopBits ?? 1,
    parity: config.modbus.parity ?? 'none',
    timeout: config.modbus.timeout ?? 1000,
  });

  const bus = new ModbusBusActor(
    driver,
    { maxAttempts: DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS, delayMs: 5000 },
    configRepo.getConfiguredSlaveIds(),
  );

  const clientId = getOrCreateClientId(MQTT_CLIENT_ID_FILE);
  const lockerUuid = process.env.LOCKER_UUID?.trim() || clientId;
  const brokerUrl = process.env.MQTT_BROKER_URL?.trim() || DEFAULT_MQTT_BROKER_URL;

  if (!credentialStore.isProvisioned()) {
    const token = process.env.PROVISIONING_TOKEN?.trim();
    if (!token) {
      throw new Error('PROVISIONING_TOKEN is required for first-time provisioning');
    }
    await provisionDevice({
      transport,
      brokerUrl,
      clientId,
      provisioningToken: token,
      credentialStore,
    });
    await sleep(5000);
  }

  const credentials = credentialStore.getCredentials();
  if (!credentials) {
    throw new Error('MQTT credentials unavailable after provisioning');
  }

  await transport.connect(brokerUrl, {
    username: credentials.username,
    password: credentials.password,
    clientId,
    ...connectionLostWillOptions(lockerUuid),
  });

  const commandTopic = `locker/${lockerUuid}/command`;
  const responseTopic = `locker/${lockerUuid}/response`;
  const heartbeatTopic = `locker/${lockerUuid}/state/heartbeat`;
  const snapshotTopic = `locker/${lockerUuid}/state/compartments`;

  const outbound = new OutboundMqttAdapter(
    (topic, payload, options) => transport.publish(topic, payload, options),
    responseTopic,
  );

  const scheduler = new RunAfterCompleteScheduler();
  const appLogger = createWinstonLoggerPort();
  const openCompartment = new OpenCompartmentUseCase(bus, configRepo, scheduler);
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    configRepo,
    outbound,
    snapshotTopic,
    appLogger,
  );
  const heartbeat = new HeartbeatUseCase(
    outbound,
    heartbeatTopic,
    configRepo.getHeartbeatIntervalSeconds() * 1000,
    appLogger,
  );

  const applyConfig = new ApplyConfigUseCase({
    overlayStore: new FileRuntimeOverlayStore(),
    config: configRepo,
    bus,
    restartHeartbeat: () => heartbeat.restart(configRepo.getHeartbeatIntervalSeconds() * 1000),
    restartPolling: () => {
      void pollSnapshot.pollAndPublish(true);
    },
  });

  const dispatcher = new CommandDispatcher(
    new InboundProtocolGuard(dedupStore),
    outbound,
    dedupStore,
  );
  dispatcher.register(
    createOpenCompartmentHandler({
      openCompartment,
      outbound,
      pollSnapshot,
    }),
  );
  dispatcher.register(createApplyConfigHandler({ applyConfig, outbound }));

  transport.onMessage((topic, payload) => {
    if (topic === commandTopic) {
      void dispatcher.dispatch(topic, payload.toString());
    }
  });

  await transport.subscribe(commandTopic);

  await bus.connect();
  await runStartupFailsafe(bus);
  heartbeat.start();

  const pollIntervalMs = 5000;
  const pollTimer = setInterval(() => {
    void pollSnapshot.pollAndPublish();
  }, pollIntervalMs);

  logger.info('locker-client-v2 started', { lockerUuid, clientId });

  return {
    async shutdown() {
      clearInterval(pollTimer);
      openCompartment.stopAllMonitoring();
      heartbeat.stop();
      await bus.disconnect();
      await transport.disconnect();
    },
  };
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
