import assert from "node:assert/strict";
import test from "node:test";
import {
  compartmentSnapshotKey,
  getModbusPollingErrorDetails,
  getUniqueConfiguredAddressesForSlave,
  isReconnectableModbusError,
  shouldPublishCompartmentSnapshot,
  type CompartmentSnapshotEntry,
} from "../services/coilPollingService";

test("compartmentSnapshotKey is stable for compartment order", () => {
  const a: CompartmentSnapshotEntry[] = [
    { compartment_number: 2, door_state: "closed" },
    { compartment_number: 1, door_state: "open" },
  ];
  const b: CompartmentSnapshotEntry[] = [
    { compartment_number: 1, door_state: "open" },
    { compartment_number: 2, door_state: "closed" },
  ];
  assert.equal(compartmentSnapshotKey(a), compartmentSnapshotKey(b));
});

test("first snapshot vs null last key implies publish", () => {
  const entries: CompartmentSnapshotEntry[] = [
    { compartment_number: 1, door_state: "closed" },
  ];
  assert.equal(shouldPublishCompartmentSnapshot(null, entries), true);
});

test("identical subsequent snapshot implies no publish", () => {
  const entries: CompartmentSnapshotEntry[] = [
    { compartment_number: 1, door_state: "closed" },
    { compartment_number: 2, door_state: "open" },
  ];
  const last = compartmentSnapshotKey(entries);
  assert.equal(shouldPublishCompartmentSnapshot(last, entries), false);
});

test("changed door_state implies publish again", () => {
  const first: CompartmentSnapshotEntry[] = [
    { compartment_number: 1, door_state: "closed" },
  ];
  const last = compartmentSnapshotKey(first);
  const second: CompartmentSnapshotEntry[] = [
    { compartment_number: 1, door_state: "open" },
  ];
  assert.equal(shouldPublishCompartmentSnapshot(last, second), true);
});

test("configured polling addresses are deduplicated and scoped by slave", () => {
  const addresses = getUniqueConfiguredAddressesForSlave(
    [
      { compartment_number: 1, slaveId: 1, address: 0 },
      { compartment_number: 2, slaveId: 2, address: 2 },
      { compartment_number: 3, slaveId: 2, address: 1 },
      { compartment_number: 4, slaveId: 2, address: 1 },
      { compartment_number: 5, slaveId: 2, address: 8 },
    ],
    2,
    8,
  );

  assert.deepEqual(addresses, [1, 2]);
});

test("reconnectable Modbus errors are limited to transport failures", () => {
  assert.equal(isReconnectableModbusError(new Error("Port Not Open")), true);
  assert.equal(isReconnectableModbusError(new Error("connect ECONNREFUSED")), true);
  assert.equal(isReconnectableModbusError(new Error("Timed out")), false);
  assert.equal(isReconnectableModbusError("ECONNREFUSED"), false);
});

test("polling error details keep message fields from plain Modbus error objects", () => {
  assert.deepEqual(
    getModbusPollingErrorDetails({
      name: "TransactionTimedOutError",
      message: "Timed out",
      errno: "ETIMEDOUT",
    }),
    {
      errorName: "TransactionTimedOutError",
      errorMessage: "Timed out",
      errno: "ETIMEDOUT",
    },
  );
});
