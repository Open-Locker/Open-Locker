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
import { DATA_DIR, MQTT_CLIENT_ID_FILE } from '../infrastructure/paths';
import { ensurePrivateDirectory } from '../infrastructure/file-persistence';

/**
 * How long any single shutdown step may take before the sequence moves on.
 * Comfortably longer than a healthy close, short enough that several stuck
 * steps still finish inside a raised `stop_grace_period`.
 */
export const SHUTDOWN_STEP_TIMEOUT_MS = 5_000;

export interface AppContext {
  shutdown(): Promise<void>;
}

export async function createApp(): Promise<AppContext> {
  ensurePrivateDirectory(DATA_DIR);
  const configRepo = new YamlConfigRepository(new FileRuntimeOverlayStore());
  const config = configRepo.load();
  const credentialStore = new FileCredentialStore();
  const dedupStore = new FileDedupStore();
  dedupStore.assertHealthy();
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
  const appLogger = createWinstonLoggerPort();

  const tracing = await createTracing({ lockerUuid, log: appLogger });
  setLogTraceContextProvider(() => tracing.currentCorrelation());
  shipLogsTo(tracing);

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
    () => configRepo.getConfiguredSlaveIds(),
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
  const connectionTopic = `locker/${lockerUuid}/state/connection`;

  const outbound = new OutboundMqttAdapter(
    (topic, payload, options) => transport.publish(topic, payload, options),
    responseTopic,
    undefined,
    tracing,
    appLogger,
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
      pollSnapshot,
    }),
  );
  dispatcher.register(createApplyConfigHandler({ applyConfig }));
  dispatcher.recoverInterruptedCommands();
  transport.onConnected(() => dispatcher.flushPendingResponses());
  await dispatcher.flushPendingResponses();

  // Commands already running when shutdown starts must finish: an open whose
  // relay has fired but whose response was never published leaves the backend
  // waiting and the door in an unknown state.
  const inFlight = new Set<Promise<void>>();

  transport.onMessage((topic, payload) => {
    const dispatched = dispatchMqttMessage(dispatcher, commandTopic, topic, payload);
    inFlight.add(dispatched);
    void dispatched.finally(() => inFlight.delete(dispatched));
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
      // Refuse new commands first, so nothing else joins the set being awaited.
      dispatcher.beginClosing();

      // Then let what is already running finish. Door detection is deliberately
      // not waited on: its window can outlast the whole shutdown, and the
      // backend's own timeout already covers a detection that never arrives.
      await Promise.allSettled(inFlight);

      // Only now are the timers safe to stop — clearing them earlier would kill
      // the monitoring belonging to a command still in progress.
      clearInterval(pollTimer);
      openCompartment.stopAllMonitoring();
      heartbeat.stop();

      // While the transport is still up: get any unsent responses out, then say
      // goodbye explicitly. The Last Will only fires on an *ungraceful* drop, so
      // without this a clean stop leaves the backend believing the bank is
      // online until the heartbeat ages out.
      await closeOrAbandon('flush-pending-responses', () => dispatcher.flushPendingResponses());
      await closeOrAbandon('publish-offline-state', () =>
        outbound.publishJson(connectionTopic, { status: 'offline', reason: 'shutdown' }),
      );

      await closeOrAbandon('transport-disconnect', () => transport.disconnect());
      await closeOrAbandon('modbus-disconnect', () => bus.disconnect());
      // Last, so spans from the shutdown path are flushed too.
      await closeOrAbandon('tracing-shutdown', () => tracing.shutdown());
    },
  };
}

interface DispatchErrorLogger {
  error(message: string, metadata?: Record<string, unknown>): unknown;
}

/**
 * Returns the dispatch promise instead of discarding it, so shutdown can wait
 * for a command that is already running. A dropped promise here means a relay
 * can fire after the transport is gone, with no response and no record.
 *
 * Already-handled failures resolve rather than reject: callers track this to
 * know when work is finished, not whether it succeeded.
 */
export function dispatchMqttMessage(
  dispatcher: Pick<CommandDispatcher, 'dispatch'>,
  commandTopic: string,
  topic: string,
  payload: Buffer,
  log: DispatchErrorLogger = logger,
): Promise<void> {
  if (topic !== commandTopic) {
    return Promise.resolve();
  }

  return dispatcher.dispatch(topic, payload.toString()).catch((error: unknown) => {
    log.error('Unexpected MQTT command dispatch failure', {
      error: error instanceof Error ? error.message : 'Unknown dispatch error',
    });
  });
}

/**
 * A shutdown step that cannot be allowed to hang the rest of the sequence.
 *
 * A wedged serial port or a half-open socket can leave `disconnect()` pending
 * forever; waiting on it would skip every later step and hand the process to
 * SIGKILL in a worse state than giving up here. By this point the command has
 * run and its response is out, so abandoning the close costs nothing the OS
 * will not reclaim on exit.
 */
export async function closeOrAbandon(
  step: string,
  close: () => Promise<void>,
  timeoutMs: number = SHUTDOWN_STEP_TIMEOUT_MS,
  log: DispatchErrorLogger = logger,
): Promise<void> {
  let timer: NodeJS.Timeout | undefined;

  const abandoned = new Promise<'abandoned'>((resolve) => {
    timer = setTimeout(() => resolve('abandoned'), timeoutMs);
  });

  try {
    const outcome = await Promise.race([close().then(() => 'closed' as const), abandoned]);

    if (outcome === 'abandoned') {
      log.error('Shutdown step did not finish; continuing without it', { step, timeoutMs });
    }
  } catch (error: unknown) {
    log.error('Shutdown step failed; continuing', {
      step,
      error: error instanceof Error ? error.message : 'Unknown shutdown error',
    });
  } finally {
    clearTimeout(timer);
  }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
