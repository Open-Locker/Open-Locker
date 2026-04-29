import assert from "node:assert/strict";
import test from "node:test";
import {
  compartmentSnapshotKey,
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
