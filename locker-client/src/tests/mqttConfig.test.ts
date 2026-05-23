import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import test, { after, before } from "node:test";

const originalEnv = {
  CONFIG_DIR: process.env.CONFIG_DIR,
  DATA_DIR: process.env.DATA_DIR,
  MQTT_DEFAULT_USERNAME: process.env.MQTT_DEFAULT_USERNAME,
  MQTT_DEFAULT_PASSWORD: process.env.MQTT_DEFAULT_PASSWORD,
};

const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "locker-client-mqtt-"));
const configDir = path.join(tempRoot, "config");
const dataDir = path.join(tempRoot, "data");

before(() => {
  fs.mkdirSync(configDir, { recursive: true });
  fs.mkdirSync(dataDir, { recursive: true });
  fs.writeFileSync(
    path.join(configDir, "locker-config.yml"),
    "modbus:\n  port: /dev/null\n",
    "utf-8",
  );

  process.env.CONFIG_DIR = configDir;
  process.env.DATA_DIR = dataDir;
});

after(() => {
  restoreEnv("CONFIG_DIR", originalEnv.CONFIG_DIR);
  restoreEnv("DATA_DIR", originalEnv.DATA_DIR);
  restoreEnv("MQTT_DEFAULT_USERNAME", originalEnv.MQTT_DEFAULT_USERNAME);
  restoreEnv("MQTT_DEFAULT_PASSWORD", originalEnv.MQTT_DEFAULT_PASSWORD);
  fs.rmSync(tempRoot, { recursive: true, force: true });
});

test("getMqttConfig reports all missing default MQTT credentials", async () => {
  delete process.env.MQTT_DEFAULT_USERNAME;
  delete process.env.MQTT_DEFAULT_PASSWORD;

  const { getMqttConfig } = await import("../config/mqtt");

  assert.throws(
    () => getMqttConfig(),
    /Missing required MQTT environment variables: MQTT_DEFAULT_USERNAME, MQTT_DEFAULT_PASSWORD/,
  );
});

test("getMqttConfig reports only the missing default MQTT credential", async () => {
  process.env.MQTT_DEFAULT_USERNAME = "default-user";
  delete process.env.MQTT_DEFAULT_PASSWORD;

  const { getMqttConfig } = await import("../config/mqtt");

  assert.throws(
    () => getMqttConfig(),
    /Missing required MQTT environment variables: MQTT_DEFAULT_PASSWORD/,
  );
});

function restoreEnv(name: string, value: string | undefined): void {
  if (value === undefined) {
    delete process.env[name];
    return;
  }

  process.env[name] = value;
}
