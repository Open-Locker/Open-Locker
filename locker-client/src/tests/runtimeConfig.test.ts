import assert from "node:assert/strict";
import test from "node:test";
import { sanitizeRuntimeConfigOverlay } from "../config/runtimeConfig";

test("sanitizeRuntimeConfigOverlay rejects compartment addresses above the supported range", () => {
  assert.throws(
    () =>
      sanitizeRuntimeConfigOverlay({
        compartments: [{ id: 1, slaveId: 1, address: 8 }],
      }),
    /invalid compartment entry/,
  );
});

test("sanitizeRuntimeConfigOverlay accepts valid overlay data", () => {
  const overlay = sanitizeRuntimeConfigOverlay({
    mqtt: { heartbeatInterval: 15 },
    compartments: [{ id: 1, slaveId: 1, address: 7 }],
    appliedConfigHash: "a".repeat(64),
    updatedAt: "2026-04-11T12:00:00Z",
  });

  assert.deepEqual(overlay, {
    mqtt: { heartbeatInterval: 15 },
    compartments: [{ id: 1, slaveId: 1, address: 7 }],
    appliedConfigHash: "a".repeat(64),
    updatedAt: "2026-04-11T12:00:00Z",
  });
});
