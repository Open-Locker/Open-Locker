import fs from "fs";
import path from "path";
import { randomBytes } from "crypto";
import { credentialsService } from "../services/credentialsService";
import { logger } from "../helper/logger";
import { configLoader, type LockerConfig } from "./configLoader";
import { MQTT_CLIENT_ID_FILE } from "./paths";

const CLIENT_ID_FILE = MQTT_CLIENT_ID_FILE;
export const DEFAULT_MQTT_BROKER_URL = "mqtt://open-locker.cloud";

function getOrGenerateClientId(): string {
  // First check if provided via environment variable
  if (process.env.MQTT_CLIENT_ID) {
    return process.env.MQTT_CLIENT_ID;
  }

  // Try to read existing client ID from file
  try {
    if (fs.existsSync(CLIENT_ID_FILE)) {
      const clientId = fs.readFileSync(CLIENT_ID_FILE, "utf-8").trim();
      if (clientId) {
        logger.info("Using persisted MQTT client ID from file");
        return clientId;
      }
    }
  } catch (error) {
    logger.warn("Failed to read persisted MQTT client ID:", error);
  }

  // Generate new client ID
  const newClientId = `locker-client-${randomBytes(4).toString("hex")}`;
  
  // Persist it for future use
  try {
    fs.writeFileSync(CLIENT_ID_FILE, newClientId, "utf-8");
    logger.info("Generated and persisted new MQTT client ID");
  } catch (error) {
    logger.warn("Failed to persist MQTT client ID:", error);
  }

  return newClientId;
}

function getCredentials(): { username?: string; password?: string } {
  // First try to load persisted credentials
  const persisted = credentialsService.getCredentials();
  if (persisted) {
    return persisted;
  }

  // No persisted credentials available
  return {};
}

function getRequiredEnv(name: string): string {
  const value = process.env[name]?.trim();

  if (!value) {
    throw new Error(`${name} is required in the environment`);
  }

  return value;
}

function getEnv(name: string, fallback: string): string {
  return process.env[name]?.trim() || fallback;
}

function parseOptionalEnvInt(name: string): number | undefined {
  const raw = process.env[name]?.trim();
  if (raw === undefined || raw === "") {
    return undefined;
  }
  const n = Number.parseInt(raw, 10);
  return Number.isFinite(n) ? n : undefined;
}

function parseOptionalEnvBool(name: string): boolean | undefined {
  const raw = process.env[name]?.trim()?.toLowerCase();
  if (raw === undefined || raw === "") {
    return undefined;
  }
  if (raw === "true" || raw === "1" || raw === "yes") {
    return true;
  }
  if (raw === "false" || raw === "0" || raw === "no") {
    return false;
  }
  return undefined;
}

export type MqttTransportSettings = {
  clean: boolean;
  keepalive: number;
  reconnectPeriod: number;
  connectTimeout: number;
  maxReconnectAttempts: number;
};

/**
 * Transport options for mqtt.js. Env vars override YAML (`config.yml` mqtt.*).
 * `maxReconnectAttempts`: 0 = unlimited (default); if > 0, client stops after N reconnect cycles.
 */
function buildMqttTransportSettings(
  fileMqtt: LockerConfig["mqtt"],
): MqttTransportSettings {
  const m = fileMqtt ?? {};
  const envClean = parseOptionalEnvBool("MQTT_CLEAN_SESSION");
  const envKeepalive = parseOptionalEnvInt("MQTT_KEEPALIVE_SECONDS");
  const envReconnect = parseOptionalEnvInt("MQTT_RECONNECT_PERIOD_MS");
  const envConnectTimeout = parseOptionalEnvInt("MQTT_CONNECT_TIMEOUT_MS");
  const envMaxAttempts = parseOptionalEnvInt("MQTT_MAX_RECONNECT_ATTEMPTS");

  const clean = envClean ?? m.cleanSession ?? false;
  const keepalive = envKeepalive ?? m.keepaliveSeconds ?? 60;
  const reconnectPeriod = envReconnect ?? m.reconnectPeriodMs ?? 5000;
  const connectTimeout = envConnectTimeout ?? m.connectTimeoutMs ?? 30000;
  const maxReconnectAttempts = envMaxAttempts ?? m.maxReconnectAttempts ?? 0;

  return {
    clean,
    keepalive: Math.max(0, keepalive),
    reconnectPeriod: Math.max(0, reconnectPeriod),
    connectTimeout: Math.max(0, connectTimeout),
    maxReconnectAttempts: Math.max(0, maxReconnectAttempts),
  };
}

export function getMqttTransportSettings(): MqttTransportSettings {
  return buildMqttTransportSettings(configLoader.loadConfig().mqtt);
}

export function getMqttConfig() {
  const config = configLoader.loadConfig();
  const credentials = getCredentials();
  const heartbeatIntervalSeconds = config.mqtt?.heartbeatInterval ?? 15;

  return {
    brokerUrl: getEnv("MQTT_BROKER_URL", DEFAULT_MQTT_BROKER_URL),
    defaultUsername: getRequiredEnv("MQTT_DEFAULT_USERNAME"),
    defaultPassword: getRequiredEnv("MQTT_DEFAULT_PASSWORD"),
    ...credentials,
    clientId: getOrGenerateClientId(),
    heartbeatInterval: heartbeatIntervalSeconds * 1000,
    mqttTransport: buildMqttTransportSettings(config.mqtt),
  };
}

export function logCurrentMqttConfig(): void {
  const mqttConfig = getMqttConfig();
  const t = mqttConfig.mqttTransport;

  logger.debug("MQTT configuration loaded:", {
    brokerUrl: mqttConfig.brokerUrl,
    username: mqttConfig.username || "(not set)",
    clientId: mqttConfig.clientId,
    heartbeatInterval: mqttConfig.heartbeatInterval,
    cleanSession: t.clean,
    keepaliveSeconds: t.keepalive,
    reconnectPeriodMs: t.reconnectPeriod,
    connectTimeoutMs: t.connectTimeout,
    maxReconnectAttempts: t.maxReconnectAttempts === 0
      ? "unlimited"
      : t.maxReconnectAttempts,
  });
}
