import {
  DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS,
  WaveshareModbusBusActor,
} from '../adapters/modbus/waveshare-modbus-bus-actor';
import { WaveshareModbusRtuDriver } from '../adapters/modbus/waveshare-modbus-rtu.driver';
import { YamlConfigRepository } from '../adapters/config/yaml-config.repository';
import { FileRuntimeOverlayStore } from '../adapters/config/runtime-overlay.store';
import { FileCredentialStore } from '../adapters/persistence/file-credential.store';
import { FileDedupStore } from '../adapters/mqtt/dedup-store';
import { MqttTransportAdapter } from '../adapters/mqtt/mqtt-transport.adapter';
import { OutboundMqttAdapter } from '../adapters/mqtt/outbound-mqtt.adapter';
import { recordCommandResponses } from '../adapters/mqtt/recording-outbound';
import { InboundProtocolGuard } from '../adapters/mqtt/inbound-protocol-guard';
import { CommandDispatcher } from '../adapters/mqtt/command-dispatcher';
import { createOpenCompartmentHandler } from '../adapters/mqtt/handlers/open-compartment.handler';
import { createApplyConfigHandler } from '../adapters/mqtt/handlers/apply-config.handler';
import { MqttDoorEventPublisher } from '../adapters/mqtt/door-event-publisher';
import { RelayFireLog } from '../domain/door-detection';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../application/open-compartment';
import { ApplyConfigUseCase } from '../application/apply-config';
import {
  COMPARTMENT_POLL_INTERVAL_MS,
  HeartbeatUseCase,
  PollCompartmentStateUseCase,
} from '../application/state-publishing';
import { RunAfterCompleteScheduler } from '../infrastructure/scheduler';
import {
  DEFAULT_MQTT_BROKER_URL,
  getOrCreateClientId,
  provisionDevice,
} from '../application/provision-device';
import { connectionLostWillOptions } from '../infrastructure/mqtt-will';
import { createTracing } from '../adapters/tracing/create-tracing';
import { logger, setLogTraceContextProvider, shipLogsTo } from '../infrastructure/logging';
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

  const clientId = getOrCreateClientId(MQTT_CLIENT_ID_FILE);
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

  // MQTT username is the locker bank UUID; all locker topics use this namespace.
  const lockerUuid = credentials.username.trim();
  if (!lockerUuid) {
    throw new Error('MQTT credentials username (locker UUID) is empty');
  }

  // Built here because the locker UUID identifies this instance, and it is only
  // known once provisioning has completed. Returns a no-op unless a collector
  // endpoint is configured, in which case the SDK is never even loaded.
  const tracing = await createTracing({ lockerUuid });
  setLogTraceContextProvider(() => tracing.currentCorrelation());
  shipLogsTo(tracing);

  const appLogger = createWinstonLoggerPort();

  const driver = new WaveshareModbusRtuDriver(
    {
      port: config.modbus.port,
      baudRate: config.modbus.baudRate ?? 9600,
      dataBits: config.modbus.dataBits ?? 8,
      stopBits: config.modbus.stopBits ?? 1,
      parity: config.modbus.parity ?? 'none',
      timeout: config.modbus.timeout ?? 1000,
    },
    {},
    appLogger,
  );

  const bus = new WaveshareModbusBusActor(
    driver,
    { maxAttempts: DEFAULT_MODBUS_MAX_RECONNECT_ATTEMPTS, delayMs: 5000 },
    configRepo.getConfiguredSlaveIds(),
    tracing,
    appLogger,
  );

  await transport.connect(brokerUrl, {
    username: credentials.username,
    password: credentials.password,
    clientId,
    ...connectionLostWillOptions(lockerUuid),
  });

  const commandTopic = `locker/${lockerUuid}/command`;
  const responseTopic = `locker/${lockerUuid}/response`;
  const eventTopic = `locker/${lockerUuid}/event`;
  const heartbeatTopic = `locker/${lockerUuid}/state/heartbeat`;
  const snapshotTopic = `locker/${lockerUuid}/state/compartments`;

  const outbound = recordCommandResponses(
    new OutboundMqttAdapter(
      (topic, payload, options) => transport.publish(topic, payload, options),
      responseTopic,
      undefined,
      tracing,
      appLogger,
    ),
    dedupStore,
  );

  const scheduler = new RunAfterCompleteScheduler();
  const doorEvents = new MqttDoorEventPublisher(outbound, eventTopic);
  const relayFireLog = new RelayFireLog();
  const detectionTimeoutMs = (): number =>
    Math.max(1, configRepo.getHeartbeatIntervalSeconds()) * 1000;

  const openCompartment = new OpenCompartmentUseCase({
    bus,
    config: configRepo,
    scheduler,
    doorEvents,
    relayFireLog,
    log: appLogger,
  });
  const pollSnapshot = new PollCompartmentStateUseCase(
    bus,
    configRepo,
    outbound,
    snapshotTopic,
    appLogger,
    { relayFireLog, doorEvents, detectionTimeoutMs },
  );
  const heartbeat = new HeartbeatUseCase(
    outbound,
    heartbeatTopic,
    configRepo.getHeartbeatIntervalSeconds() * 1000,
    appLogger,
    bus,
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
    tracing,
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
      // Nothing awaits this, so without a catch a rejection escaping dispatch
      // becomes an unhandled rejection and takes the process down.
      void dispatcher.dispatch(topic, payload.toString()).catch((error: unknown) => {
        appLogger.error('Inbound command dispatch failed', {
          topic,
          error: error instanceof Error ? error.message : String(error),
        });
      });
    }
  });

  await transport.subscribe(commandTopic);

  await bus.connect();
  await runStartupFailsafe(bus);
  heartbeat.start();

  const pollTimer = setInterval(() => {
    void pollSnapshot.pollAndPublish();
  }, COMPARTMENT_POLL_INTERVAL_MS);

  logger.info('locker-client started', { lockerUuid, clientId });

  return {
    async shutdown() {
      clearInterval(pollTimer);
      openCompartment.stopAllMonitoring();
      heartbeat.stop();
      await bus.disconnect();
      await transport.disconnect();
      // Last, so spans from the shutdown path are flushed too.
      await tracing.shutdown();
    },
  };
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
