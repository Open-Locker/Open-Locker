import assert from "node:assert/strict";
import test from "node:test";
import { configLoader } from "../config/configLoader";
import { modbusService } from "../services/modbusService";

test("getConfiguredSlaveIds keeps legacy fallback when no runtime compartments exist", () => {
  const originalGetConfig = configLoader.getConfig.bind(configLoader);
  const originalHasExplicitRuntimeCompartmentsConfig =
    configLoader.hasExplicitRuntimeCompartmentsConfig.bind(configLoader);

  configLoader.getConfig = () => ({
    modbus: {
      port: "/dev/ttyUSB0",
    },
  });
  configLoader.hasExplicitRuntimeCompartmentsConfig = () => false;

  try {
    assert.deepEqual(modbusService.getConfiguredSlaveIds(), [1]);
  } finally {
    configLoader.getConfig = originalGetConfig;
    configLoader.hasExplicitRuntimeCompartmentsConfig =
      originalHasExplicitRuntimeCompartmentsConfig;
  }
});

test("getConfiguredSlaveIds returns no fallback for explicit empty runtime compartments", () => {
  const originalGetConfig = configLoader.getConfig.bind(configLoader);
  const originalHasExplicitRuntimeCompartmentsConfig =
    configLoader.hasExplicitRuntimeCompartmentsConfig.bind(configLoader);

  configLoader.getConfig = () => ({
    modbus: {
      port: "/dev/ttyUSB0",
    },
    compartments: [],
  });
  configLoader.hasExplicitRuntimeCompartmentsConfig = () => true;

  try {
    assert.deepEqual(modbusService.getConfiguredSlaveIds(), []);
  } finally {
    configLoader.getConfig = originalGetConfig;
    configLoader.hasExplicitRuntimeCompartmentsConfig =
      originalHasExplicitRuntimeCompartmentsConfig;
  }
});
