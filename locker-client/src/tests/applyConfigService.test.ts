import assert from "node:assert/strict";
import test from "node:test";
import { LockerConfig } from "../config/configLoader";
import {
  computeAppliedConfigHash,
  RuntimeConfigOverlay,
} from "../config/runtimeConfig";
import {
  ApplyConfigExecutionError,
  ApplyConfigService,
} from "../services/applyConfigService";
import { MQTTErrorCode } from "../types/mqtt";

const baseConfig: LockerConfig = {
  mqtt: {
    heartbeatInterval: 15,
  },
  modbus: {
    port: "/dev/ttyUSB0",
    baudRate: 9600,
    dataBits: 8,
    stopBits: 1,
    parity: "none",
    timeout: 1000,
  },
  compartments: [
    { compartment_number: 1, slaveId: 1, address: 0 },
  ],
};

test("applyConfig persists normalized overlay and restarts runtime services", async () => {
  let currentOverlay: RuntimeConfigOverlay | null = null;
  let reloadCount = 0;
  let heartbeatRestartCount = 0;
  let modbusReloadCount = 0;
  let pollingRestartCount = 0;

  const service = new ApplyConfigService(
    {
      load: () => currentOverlay as any,
      save: (overlay) => {
        currentOverlay = overlay;
        return overlay;
      },
      clear: () => {
        currentOverlay = null;
      },
    },
    {
      loadConfig: () => baseConfig,
      reloadConfig: () => {
        reloadCount++;
        return baseConfig;
      },
    },
    {
      restart: () => {
        heartbeatRestartCount++;
      },
    },
    {
      reloadRuntimeConfig: async () => {
        modbusReloadCount++;
      },
    },
    {
      restart: () => {
        pollingRestartCount++;
      },
    },
  );

  const compartments = [
    { compartment_number: 2, slaveId: 2, address: 0 },
    { compartment_number: 1, slaveId: 1, address: 1 },
  ];

  const result = await service.applyConfig({
    action: "apply_config",
    transaction_id: "txn-success",
    message_id: "msg-success",
    timestamp: "2026-04-11T12:00:00Z",
    data: {
      config_hash: computeAppliedConfigHash(compartments),
      heartbeat_interval_seconds: 30,
      compartments,
    },
  });

  assert.equal(result.appliedConfigHash, computeAppliedConfigHash(compartments));
  assert.equal(result.message, "Config applied.");
  assert.equal(reloadCount, 1);
  assert.equal(heartbeatRestartCount, 1);
  assert.equal(modbusReloadCount, 1);
  assert.equal(pollingRestartCount, 1);
  assert.deepEqual((currentOverlay as any)?.mqtt, { heartbeatInterval: 30 });
  assert.deepEqual((currentOverlay as any)?.compartments, [
    { compartment_number: 1, slaveId: 1, address: 1 },
    { compartment_number: 2, slaveId: 2, address: 0 },
  ]);
  assert.equal(typeof (currentOverlay as any)?.updatedAt, "string");
});

test("applyConfig rejects invalid config hashes before changing runtime state", async () => {
  let saveCount = 0;
  let reloadCount = 0;

  const service = new ApplyConfigService(
    {
      load: () => null,
      save: (overlay) => {
        saveCount++;
        return overlay;
      },
      clear: () => undefined,
    },
    {
      loadConfig: () => baseConfig,
      reloadConfig: () => {
        reloadCount++;
        return baseConfig;
      },
    },
    { restart: () => undefined },
    { reloadRuntimeConfig: async () => undefined },
    { restart: () => undefined },
  );

  await assert.rejects(
    service.applyConfig({
      action: "apply_config",
      transaction_id: "txn-invalid",
      message_id: "msg-invalid",
      timestamp: "2026-04-11T12:00:00Z",
      data: {
        config_hash: "0".repeat(64),
        heartbeat_interval_seconds: 30,
        compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
      },
    }),
    (error: unknown) => {
      assert.ok(error instanceof ApplyConfigExecutionError);
      assert.equal(error.errorCode, MQTTErrorCode.INVALID_CONFIG);
      assert.equal(
        error.message,
        "config_hash does not match the provided compartments mapping",
      );
      return true;
    },
  );

  assert.equal(saveCount, 0);
  assert.equal(reloadCount, 0);
});

test("applyConfig restores the previous overlay when runtime reload fails", async () => {
  const previousOverlay: RuntimeConfigOverlay = {
    mqtt: { heartbeatInterval: 15 },
    compartments: [{ compartment_number: 1, slaveId: 1, address: 0 }],
    appliedConfigHash: "c".repeat(64),
    updatedAt: "2026-04-11T11:00:00Z",
  };
  let currentOverlay: RuntimeConfigOverlay | null = previousOverlay;
  const savedOverlays: RuntimeConfigOverlay[] = [];
  let clearCount = 0;
  let modbusReloadAttempts = 0;

  const service = new ApplyConfigService(
    {
      load: () => currentOverlay as any,
      save: (overlay) => {
        currentOverlay = overlay;
        savedOverlays.push(overlay as any);
        return overlay;
      },
      clear: () => {
        currentOverlay = null;
        clearCount++;
      },
    },
    {
      loadConfig: () => baseConfig,
      reloadConfig: () => baseConfig,
    },
    { restart: () => undefined },
    {
      reloadRuntimeConfig: async () => {
        modbusReloadAttempts++;

        if (modbusReloadAttempts === 1) {
          throw new Error("modbus reconnect failed");
        }
      },
    },
    { restart: () => undefined },
  );

  await assert.rejects(
    service.applyConfig({
      action: "apply_config",
      transaction_id: "txn-rollback",
      message_id: "msg-rollback",
      timestamp: "2026-04-11T12:00:00Z",
      data: {
        config_hash: computeAppliedConfigHash([
          { compartment_number: 2, slaveId: 2, address: 1 },
        ]),
        heartbeat_interval_seconds: 45,
        compartments: [{ compartment_number: 2, slaveId: 2, address: 1 }],
      },
    }),
    (error: unknown) => {
      assert.ok(error instanceof ApplyConfigExecutionError);
      assert.equal(error.errorCode, MQTTErrorCode.HARDWARE_ERROR);
      assert.equal(error.message, "modbus reconnect failed");
      return true;
    },
  );

  assert.equal(clearCount, 0);
  assert.equal(modbusReloadAttempts, 2);
  assert.deepEqual(savedOverlays[savedOverlays.length - 1], previousOverlay);
  assert.deepEqual(currentOverlay, previousOverlay);
});
