"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.DEFAULT_MQTT_BROKER_URL = void 0;
exports.getOrCreateClientId = getOrCreateClientId;
exports.getRequiredProvisioningDefaults = getRequiredProvisioningDefaults;
exports.provisionDevice = provisionDevice;
const crypto_1 = require("crypto");
const fs_1 = __importDefault(require("fs"));
const paths_1 = require("../infrastructure/paths");
exports.DEFAULT_MQTT_BROKER_URL = "mqtt://open-locker.cloud";
function getOrCreateClientId() {
    if (process.env.MQTT_CLIENT_ID) {
        return process.env.MQTT_CLIENT_ID;
    }
    if (fs_1.default.existsSync(paths_1.MQTT_CLIENT_ID_FILE)) {
        const existing = fs_1.default.readFileSync(paths_1.MQTT_CLIENT_ID_FILE, "utf8").trim();
        if (existing) {
            return existing;
        }
    }
    const clientId = `locker-client-${(0, crypto_1.randomBytes)(4).toString("hex")}`;
    fs_1.default.writeFileSync(paths_1.MQTT_CLIENT_ID_FILE, clientId, "utf8");
    return clientId;
}
function getRequiredProvisioningDefaults() {
    const username = process.env.MQTT_DEFAULT_USERNAME?.trim();
    const password = process.env.MQTT_DEFAULT_PASSWORD?.trim();
    if (!username || !password) {
        throw new Error("Missing MQTT_DEFAULT_USERNAME or MQTT_DEFAULT_PASSWORD environment variables");
    }
    return { defaultUsername: username, defaultPassword: password };
}
async function provisionDevice(options) {
    const { defaultUsername, defaultPassword } = getRequiredProvisioningDefaults();
    const replyTopic = `locker/provisioning/reply/${options.clientId}`;
    const registerTopic = `locker/register/${options.provisioningToken}`;
    await options.transport.connect(options.brokerUrl, {
        username: defaultUsername,
        password: defaultPassword,
        clientId: options.clientId,
    });
    await options.transport.subscribe(replyTopic);
    const credentials = await waitForProvisioningReply(options.transport, replyTopic, registerTopic, options.clientId);
    options.credentialStore.saveCredentials(credentials);
    options.credentialStore.markProvisioned();
    await options.transport.disconnect();
}
function waitForProvisioningReply(transport, replyTopic, registerTopic, clientId) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            reject(new Error("Provisioning timed out"));
        }, 30_000);
        transport.onMessage((topic, payload) => {
            if (topic !== replyTopic) {
                return;
            }
            try {
                const message = JSON.parse(payload.toString());
                clearTimeout(timeout);
                if (message.result === "success" && message.username && message.password) {
                    resolve({ username: message.username, password: message.password });
                    return;
                }
                reject(new Error(message.message ?? "Provisioning failed"));
            }
            catch (error) {
                reject(error);
            }
        });
        void transport
            .publish(registerTopic, JSON.stringify({
            client_id: clientId,
            message_id: (0, crypto_1.randomUUID)(),
            timestamp: new Date().toISOString(),
        }), { qos: 1 })
            .catch(reject);
    });
}
