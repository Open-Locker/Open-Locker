"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.createApp = createApp;
const bus_actor_1 = require("../adapters/modbus/bus-actor");
const modbus_rtu_driver_1 = require("../adapters/modbus/modbus-rtu.driver");
const yaml_config_repository_1 = require("../adapters/config/yaml-config.repository");
const runtime_overlay_store_1 = require("../adapters/config/runtime-overlay.store");
const file_credential_store_1 = require("../adapters/persistence/file-credential.store");
const dedup_store_1 = require("../adapters/mqtt/dedup-store");
const mqtt_transport_adapter_1 = require("../adapters/mqtt/mqtt-transport.adapter");
const outbound_mqtt_adapter_1 = require("../adapters/mqtt/outbound-mqtt.adapter");
const inbound_protocol_guard_1 = require("../adapters/mqtt/inbound-protocol-guard");
const command_dispatcher_1 = require("../adapters/mqtt/command-dispatcher");
const open_compartment_handler_1 = require("../adapters/mqtt/handlers/open-compartment.handler");
const apply_config_handler_1 = require("../adapters/mqtt/handlers/apply-config.handler");
const open_compartment_1 = require("../application/open-compartment");
const apply_config_1 = require("../application/apply-config");
const state_publishing_1 = require("../application/state-publishing");
const scheduler_1 = require("../infrastructure/scheduler");
const provision_device_1 = require("../application/provision-device");
const mqtt_will_1 = require("../infrastructure/mqtt-will");
const logging_1 = require("../infrastructure/logging");
async function createApp() {
    const configRepo = new yaml_config_repository_1.YamlConfigRepository(new runtime_overlay_store_1.FileRuntimeOverlayStore());
    const config = configRepo.load();
    const credentialStore = new file_credential_store_1.FileCredentialStore();
    const dedupStore = new dedup_store_1.FileDedupStore();
    const transport = new mqtt_transport_adapter_1.MqttTransportAdapter(configRepo.getMqttTransportSettings());
    const driver = new modbus_rtu_driver_1.ModbusRtuDriver({
        port: config.modbus.port,
        baudRate: config.modbus.baudRate ?? 9600,
        dataBits: config.modbus.dataBits ?? 8,
        stopBits: config.modbus.stopBits ?? 1,
        parity: config.modbus.parity ?? 'none',
        timeout: config.modbus.timeout ?? 1000,
    });
    const bus = new bus_actor_1.ModbusBusActor(driver, { maxAttempts: 0, delayMs: 5000 }, configRepo.getConfiguredSlaveIds());
    const clientId = (0, provision_device_1.getOrCreateClientId)();
    const lockerUuid = process.env.LOCKER_UUID?.trim() || clientId;
    const brokerUrl = process.env.MQTT_BROKER_URL?.trim() || provision_device_1.DEFAULT_MQTT_BROKER_URL;
    if (!credentialStore.isProvisioned()) {
        const token = process.env.PROVISIONING_TOKEN?.trim();
        if (!token) {
            throw new Error('PROVISIONING_TOKEN is required for first-time provisioning');
        }
        await (0, provision_device_1.provisionDevice)({
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
        ...(0, mqtt_will_1.connectionLostWillOptions)(lockerUuid),
    });
    const commandTopic = `locker/${lockerUuid}/command`;
    const responseTopic = `locker/${lockerUuid}/response`;
    const heartbeatTopic = `locker/${lockerUuid}/state/heartbeat`;
    const snapshotTopic = `locker/${lockerUuid}/state/compartments`;
    const outbound = new outbound_mqtt_adapter_1.OutboundMqttAdapter((topic, payload, options) => transport.publish(topic, payload, options), responseTopic);
    const scheduler = new scheduler_1.RunAfterCompleteScheduler();
    const openCompartment = new open_compartment_1.OpenCompartmentUseCase(bus, configRepo, scheduler);
    const pollSnapshot = new state_publishing_1.PollCompartmentStateUseCase(bus, configRepo, outbound, snapshotTopic);
    const heartbeat = new state_publishing_1.HeartbeatUseCase(outbound, heartbeatTopic, configRepo.getHeartbeatIntervalSeconds() * 1000);
    const applyConfig = new apply_config_1.ApplyConfigUseCase({
        overlayStore: new runtime_overlay_store_1.FileRuntimeOverlayStore(),
        config: configRepo,
        bus: extendBusForReload(bus, configRepo, driver),
        restartHeartbeat: () => heartbeat.restart(configRepo.getHeartbeatIntervalSeconds() * 1000),
        restartPolling: () => {
            void pollSnapshot.pollAndPublish(true);
        },
    });
    const dispatcher = new command_dispatcher_1.CommandDispatcher(new inbound_protocol_guard_1.InboundProtocolGuard(dedupStore), outbound);
    dispatcher.register((0, open_compartment_handler_1.createOpenCompartmentHandler)({
        openCompartment,
        outbound,
        dedup: dedupStore,
        pollSnapshot,
    }));
    dispatcher.register((0, apply_config_handler_1.createApplyConfigHandler)({ applyConfig, outbound }));
    transport.onMessage((topic, payload) => {
        if (topic === commandTopic) {
            void dispatcher.dispatch(topic, payload.toString());
        }
    });
    await transport.subscribe(commandTopic);
    await bus.connect();
    await (0, open_compartment_1.runStartupFailsafe)(bus);
    heartbeat.start();
    const pollIntervalMs = 5000;
    const pollTimer = setInterval(() => {
        void pollSnapshot.pollAndPublish();
    }, pollIntervalMs);
    logging_1.logger.info('locker-client-v2 started', { lockerUuid, clientId });
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
function extendBusForReload(bus, configRepo, driver) {
    return Object.assign(bus, {
        async reloadRuntimeConfig() {
            configRepo.reload();
            if (!driver.isOpen()) {
                await bus.connect();
            }
        },
    });
}
function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}
