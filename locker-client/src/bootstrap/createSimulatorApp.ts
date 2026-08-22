import { randomBytes } from 'crypto';
import { CommandDispatcher } from '../adapters/mqtt/command-dispatcher';
import { InMemoryDedupStore } from '../adapters/mqtt/dedup-store';
import { createApplyConfigHandler } from '../adapters/mqtt/handlers/apply-config.handler';
import { createOpenCompartmentHandler } from '../adapters/mqtt/handlers/open-compartment.handler';
import { InboundProtocolGuard } from '../adapters/mqtt/inbound-protocol-guard';
import { MqttTransportAdapter } from '../adapters/mqtt/mqtt-transport.adapter';
import { OutboundMqttAdapter } from '../adapters/mqtt/outbound-mqtt.adapter';
import { InMemoryCredentialStore } from '../adapters/simulator/in-memory-credential.store';
import { InMemoryLockerBus, busTargetKey } from '../adapters/simulator/in-memory-locker-bus';
import { InMemoryRuntimeOverlayStore } from '../adapters/simulator/in-memory-overlay.store';
import { ScenarioConfigRepository } from '../adapters/simulator/scenario-config.repository';
import type { SimulatorBankScenario, SimulatorScenario } from '../adapters/simulator/scenario';
import {
  EphemeralCredentialCache,
  type CredentialCache,
} from '../adapters/simulator/simulator-credential-cache';
import {
  createConsoleTrafficLogger,
  silentTrafficLogger,
  type TrafficLogger,
} from '../adapters/simulator/traffic-log';
import { ApplyConfigUseCase } from '../application/apply-config';
import { MqttDoorEventPublisher } from '../adapters/mqtt/door-event-publisher';
import { RelayFireLog } from '../domain/door-detection';
import { OpenCompartmentUseCase, runStartupFailsafe } from '../application/open-compartment';
import {
  COMPARTMENT_POLL_INTERVAL_MS,
  HeartbeatUseCase,
  PollCompartmentStateUseCase,
} from '../application/state-publishing';
import { DEFAULT_MQTT_BROKER_URL, provisionDevice } from '../application/provision-device';
import type { DoorState } from '../domain/compartment';
import { createTracing } from '../adapters/tracing/create-tracing';
import { logger, setLogTraceContextProvider, shipLogsTo } from '../infrastructure/logging';
import { connectionLostWillOptions } from '../infrastructure/mqtt-will';
import type { OutboundPublishOptions } from '../ports/mqtt.port';
import { noopTracing, type TracingPort } from '../ports/tracing.port';
import { RunAfterCompleteScheduler } from '../infrastructure/scheduler';
import { createWinstonLoggerPort } from '../infrastructure/winston-logger.adapter';

/**
 * Fleet simulator composition root.
 *
 * Deliberately mirrors `createApp.ts` step for step, with exactly two
 * substitutions: the Modbus bus becomes `InMemoryLockerBus`, and the file-backed
 * stores become in-memory ones. Everything between — use cases, dispatcher,
 * envelope, dedup, schemas — is the production code path, which is what keeps
 * simulator payloads contract-valid by construction rather than by discipline.
 *
 * `createApp.ts` and `main.ts` are untouched and know nothing about this file.
 */
export interface SimulatedDevice {
  readonly name: string;
  readonly lockerUuid: string;
  readonly compartmentNumbers: number[];
  /** Scripted or manual door change; publishes a fresh retained snapshot. */
  setDoorState(compartmentNumber: number, state: DoorState): Promise<void>;
  getDoorState(compartmentNumber: number): DoorState | null;
  /** Jammed compartments pulse the relay but their door never moves. */
  setJammed(compartmentNumber: number, jammed: boolean): void;
  isJammed(compartmentNumber: number): boolean;
  shutdown(): Promise<void>;
}

export interface SimulatorContext {
  readonly devices: SimulatedDevice[];
  findDevice(nameOrUuid: string): SimulatedDevice | null;
  shutdown(): Promise<void>;
}

/** The contract's door states; anything else is rejected by the backend. */
const DOOR_STATES: DoorState[] = ['open', 'closed', 'unknown'];

export type PublishFn = (
  topic: string,
  payload: string,
  options?: OutboundPublishOptions,
) => Promise<void>;

export interface WiredSimulatedDevice {
  device: SimulatedDevice;
  /** Connect the fake bus, begin heartbeats, publish the seeded snapshot. */
  start(): Promise<void>;
  /** Feed inbound command payloads in, exactly as the transport would. */
  dispatch(topic: string, rawMessage: string): Promise<void>;
  topics: {
    command: string;
    response: string;
    heartbeat: string;
    snapshot: string;
  };
}

export interface WireSimulatedDeviceOptions {
  bank: SimulatorBankScenario;
  lockerUuid: string;
  publish: PublishFn;
  /** Extra teardown run before the device's own, e.g. closing a transport. */
  onShutdown?: () => Promise<void>;
  /** Disable the background poll timer; tests drive polling explicitly. */
  pollIntervalMs?: number | null;
  /** Echoes MQTT traffic to the console; silent by default (tests, embedding). */
  trafficLog?: TrafficLogger;
  /** No-op unless a collector is configured, exactly as on real hardware. */
  tracing?: TracingPort;
}

/**
 * Wire one simulated device on top of an arbitrary publish function.
 *
 * Split out from the MQTT connection so the same wiring can be exercised in
 * tests without a broker: the transport is the only thing tests replace, so
 * what they verify is the production path, not a re-implementation of it.
 */
export function wireSimulatedDevice(options: WireSimulatedDeviceOptions): WiredSimulatedDevice {
  const { bank, lockerUuid } = options;
  const trafficLog = options.trafficLog ?? silentTrafficLogger;

  // Wrapped from the outside so the use cases below stay exactly as production
  // built them — the log observes the seam, it does not participate in it.
  const publish: PublishFn = async (topic, payload, publishOptions) => {
    trafficLog.outbound(topic, payload, publishOptions);
    return options.publish(topic, payload, publishOptions);
  };

  const overlayStore = new InMemoryRuntimeOverlayStore();
  const configRepo = new ScenarioConfigRepository(bank, overlayStore);
  const dedupStore = new InMemoryDedupStore();

  const topics = {
    command: `locker/${lockerUuid}/command`,
    response: `locker/${lockerUuid}/response`,
    event: `locker/${lockerUuid}/event`,
    heartbeat: `locker/${lockerUuid}/state/heartbeat`,
    snapshot: `locker/${lockerUuid}/state/compartments`,
    connection: `locker/${lockerUuid}/state/connection`,
  };

  const bus = new InMemoryLockerBus({
    slaveIds: configRepo.getConfiguredSlaveIds(),
    initialDoorStates: new Map(
      bank.compartments.map((compartment) => [
        busTargetKey(compartment.slaveId, compartment.address),
        compartment.door_state,
      ]),
    ),
    jammedTargets: new Set(
      bank.compartments
        .filter((compartment) => compartment.jammed)
        .map((compartment) => busTargetKey(compartment.slaveId, compartment.address)),
    ),
    latencyMs: bank.latency_ms,
  });

  const tracing = options.tracing ?? noopTracing;
  const outbound = new OutboundMqttAdapter(publish, topics.response, undefined, tracing);
  const scheduler = new RunAfterCompleteScheduler();
  const appLogger = createWinstonLoggerPort();

  const doorEvents = new MqttDoorEventPublisher(outbound, topics.event);
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
    topics.snapshot,
    appLogger,
    { relayFireLog, doorEvents, detectionTimeoutMs },
  );
  const heartbeat = new HeartbeatUseCase(
    outbound,
    topics.heartbeat,
    configRepo.getHeartbeatIntervalSeconds() * 1000,
    appLogger,
    bus,
  );

  const applyConfig = new ApplyConfigUseCase({
    overlayStore,
    config: configRepo,
    bus,
    restartHeartbeat: () => heartbeat.restart(configRepo.getHeartbeatIntervalSeconds() * 1000),
    restartPolling: () => {
      void pollSnapshot.pollAndPublish(true);
    },
  });

  const inFlight = new Set<Promise<void>>();

  const dispatcher = new CommandDispatcher(
    new InboundProtocolGuard(dedupStore),
    outbound,
    dedupStore,
    tracing,
  );
  dispatcher.register(createOpenCompartmentHandler({ openCompartment, pollSnapshot }));
  dispatcher.register(createApplyConfigHandler({ applyConfig }));

  const resolveTarget = (compartmentNumber: number) =>
    configRepo.getCompartmentConfig(compartmentNumber);

  const device: SimulatedDevice = {
    name: bank.name,
    lockerUuid,
    get compartmentNumbers() {
      return (configRepo.load().compartments ?? [])
        .map((compartment) => compartment.compartment_number)
        .toSorted((left, right) => left - right);
    },
    async setDoorState(compartmentNumber: number, state: DoorState) {
      // Runtime guard, not just a type: publishing a door_state outside the
      // contract enum makes the backend silently drop the whole snapshot.
      if (!DOOR_STATES.includes(state)) {
        throw new Error(`Invalid door state "${state}"; expected one of ${DOOR_STATES.join(', ')}`);
      }

      const target = resolveTarget(compartmentNumber);
      if (!target) {
        throw new Error(
          `Bank "${bank.name}" has no compartment ${compartmentNumber} in its current mapping`,
        );
      }

      bus.setDoorState(target.slaveId, target.address, state);
      await pollSnapshot.pollAndPublish(true);
    },
    getDoorState(compartmentNumber: number) {
      const target = resolveTarget(compartmentNumber);

      return target ? bus.getDoorState(target.slaveId, target.address) : null;
    },
    setJammed(compartmentNumber: number, jammed: boolean) {
      const target = resolveTarget(compartmentNumber);
      if (!target) {
        throw new Error(
          `Bank "${bank.name}" has no compartment ${compartmentNumber} in its current mapping`,
        );
      }

      bus.setJammed(target.slaveId, target.address, jammed);
    },
    isJammed(compartmentNumber: number) {
      const target = resolveTarget(compartmentNumber);

      return target ? bus.isJammed(target.slaveId, target.address) : false;
    },
    async shutdown() {
      // Same ordering as createApp: refuse, drain, then tear down. The
      // simulator is only a faithful stand-in if it stops the way the real
      // client stops.
      dispatcher.beginClosing();
      await Promise.allSettled(inFlight);

      if (pollTimer) {
        clearInterval(pollTimer);
      }
      openCompartment.stopAllMonitoring();
      heartbeat.stop();

      // The Last Will only fires on an ungraceful drop, so a clean stop has to
      // say so itself — otherwise the backend keeps the bank online until the
      // heartbeat ages out. Same reason as in createApp.
      await outbound.publishJson(topics.connection, { status: 'offline', reason: 'shutdown' });

      await bus.disconnect();
      await options.onShutdown?.();
    },
  };

  let pollTimer: ReturnType<typeof setInterval> | null = null;

  const start = async () => {
    await bus.connect();
    await runStartupFailsafe(bus);
    heartbeat.start();

    // Publish the seeded door states immediately so the backend read model is
    // populated without waiting for a command or a state change.
    await pollSnapshot.pollAndPublish(true);

    const interval =
      options.pollIntervalMs === undefined ? COMPARTMENT_POLL_INTERVAL_MS : options.pollIntervalMs;

    if (interval !== null) {
      pollTimer = setInterval(() => {
        void pollSnapshot.pollAndPublish();
      }, interval);
    }
  };

  return {
    device,
    start,
    dispatch: (topic: string, rawMessage: string) => {
      trafficLog.inbound(topic, rawMessage);

      const dispatched = dispatcher.dispatch(topic, rawMessage);
      inFlight.add(dispatched);
      // `.catch` rather than `.finally`: a rejection here — a refusal whose own
      // publish failed, say — would otherwise surface as an unhandled rejection
      // on this second chain and take the process down mid-shutdown. The caller
      // still sees the original promise, rejection and all.
      void dispatched.catch(() => undefined).finally(() => inFlight.delete(dispatched));

      return dispatched;
    },
    topics,
  };
}

export interface CreateSimulatorOptions {
  scenario: SimulatorScenario;
  /** Overrides the scenario's `broker_url`; typically from `MQTT_BROKER_URL`. */
  brokerUrl?: string;
  /**
   * Where credentials issued at first provisioning are remembered. A bank can
   * only provision once (see `SimulatorCredentialCache`), so without this the
   * simulator would run for that bank exactly once.
   */
  credentialCache?: CredentialCache;
  /** Echo every MQTT message to the console. On by default: it is a simulator. */
  logTraffic?: boolean;
}

export async function createSimulatorApp(
  options: CreateSimulatorOptions,
): Promise<SimulatorContext> {
  const brokerUrl =
    options.brokerUrl?.trim() || options.scenario.broker_url?.trim() || DEFAULT_MQTT_BROKER_URL;
  const credentialCache = options.credentialCache ?? new EphemeralCredentialCache();
  const logTraffic = options.logTraffic ?? true;

  const devices: SimulatedDevice[] = [];

  try {
    for (const bank of options.scenario.banks) {
      devices.push(await startSimulatedDevice(bank, brokerUrl, credentialCache, logTraffic));
    }
  } catch (error) {
    // Never leave half a fleet connected: unwind everything already started.
    await Promise.allSettled(devices.map((device) => device.shutdown()));
    throw error;
  }

  logger.info('locker simulator started', {
    brokerUrl,
    devices: devices.map((device) => ({ name: device.name, lockerUuid: device.lockerUuid })),
  });

  return {
    devices,
    findDevice(nameOrUuid: string) {
      const needle = nameOrUuid.trim().toLowerCase();

      return (
        devices.find(
          (device) =>
            device.name.toLowerCase() === needle || device.lockerUuid.toLowerCase() === needle,
        ) ?? null
      );
    },
    async shutdown() {
      await Promise.allSettled(devices.map((device) => device.shutdown()));
    },
  };
}

async function startSimulatedDevice(
  bank: SimulatorBankScenario,
  brokerUrl: string,
  credentialCache: CredentialCache,
  logTraffic: boolean,
): Promise<SimulatedDevice> {
  const credentialStore = new InMemoryCredentialStore();
  const overlayStore = new InMemoryRuntimeOverlayStore();
  const transport = new MqttTransportAdapter(
    new ScenarioConfigRepository(bank, overlayStore).getMqttTransportSettings(),
  );

  // Unique per device and per run, so simulated devices never collide with each
  // other or with a real client's persisted id.
  const clientId = `locker-sim-${randomBytes(4).toString('hex')}`;

  // A locker bank provisions exactly once for its lifetime: the backend sets
  // `provisioned_at` and rejects every later attempt. Reuse what we were issued
  // the first time, and only reach for the provisioning flow when we have
  // nothing cached.
  const cached = credentialCache.get(bank.provisioning_token);

  if (cached) {
    credentialStore.saveCredentials(cached);
    logger.info('reusing cached simulator credentials', {
      bank: bank.name,
      cache: credentialCache.location,
    });
  } else {
    await provisionDevice({
      transport,
      brokerUrl,
      clientId,
      provisioningToken: bank.provisioning_token,
      credentialStore,
    });
  }

  const credentials = credentialStore.getCredentials();
  if (!credentials) {
    throw new Error(`Provisioning produced no credentials for bank "${bank.name}"`);
  }

  if (!cached) {
    credentialCache.set(bank.provisioning_token, credentials);
  }

  // MQTT username is the locker bank UUID; all locker topics use this namespace.
  const lockerUuid = credentials.username.trim();
  if (!lockerUuid) {
    throw new Error(`Provisioned credentials for bank "${bank.name}" have an empty locker UUID`);
  }

  await transport.connect(brokerUrl, {
    username: credentials.username,
    password: credentials.password,
    clientId,
    ...connectionLostWillOptions(lockerUuid),
  });

  // Each simulated bank reports as its own instance, so a fleet run looks to the
  // trace backend exactly like a fleet of Pis.
  // Passing the logger matters here: the simulator is how the tracing path gets
  // exercised without hardware, so a misconfigured collector during a run should
  // say so rather than be discarded.
  const tracing = await createTracing({ lockerUuid, log: logger });
  setLogTraceContextProvider(() => tracing.currentCorrelation());
  shipLogsTo(tracing);

  const wired = wireSimulatedDevice({
    bank,
    lockerUuid,
    publish: (topic, payload, publishOptions) => transport.publish(topic, payload, publishOptions),
    onShutdown: async () => {
      await transport.disconnect();
      await tracing.shutdown();
    },
    trafficLog: logTraffic ? createConsoleTrafficLogger(bank.name) : silentTrafficLogger,
    tracing,
  });

  transport.onMessage((topic, payload) => {
    if (topic === wired.topics.command) {
      // Matches the boundary in createApp: a dispatch failure is logged, not
      // left to reach the process-level handler and take the simulator down.
      void wired.dispatch(topic, payload.toString()).catch((error: unknown) => {
        logger.error('Unexpected MQTT command dispatch failure', {
          bank: bank.name,
          error: error instanceof Error ? error.message : 'Unknown dispatch error',
        });
      });
    }
  });

  await transport.subscribe(wired.topics.command);
  await wired.start();

  logger.info('simulated locker started', { bank: bank.name, lockerUuid, clientId });

  return wired.device;
}
