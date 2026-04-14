import assert from "node:assert/strict";
import test from "node:test";
import { SerialOperationQueue } from "../helper/serialOperationQueue";

test("serializes concurrent operations in submission order", async () => {
  const queue = new SerialOperationQueue();
  const events: string[] = [];
  let releaseFirst!: () => void;

  const firstOperation = queue.enqueue("first", async () => {
    events.push("first:start");

    await new Promise<void>((resolve) => {
      releaseFirst = resolve;
    });

    events.push("first:end");
    return "first-result";
  });

  const secondOperation = queue.enqueue("second", async () => {
    events.push("second:start");
    events.push("second:end");
    return "second-result";
  });

  await Promise.resolve();

  assert.deepEqual(events, ["first:start"]);

  releaseFirst();

  const [firstResult, secondResult] = await Promise.all([
    firstOperation,
    secondOperation,
  ]);

  assert.equal(firstResult, "first-result");
  assert.equal(secondResult, "second-result");
  assert.deepEqual(events, [
    "first:start",
    "first:end",
    "second:start",
    "second:end",
  ]);
});

test("continues processing queued operations after a failure", async () => {
  const failures: string[] = [];
  const queue = new SerialOperationQueue((operationName, error) => {
    failures.push(
      `${operationName}:${error instanceof Error ? error.message : String(error)}`,
    );
  });
  const events: string[] = [];

  const firstOperation = queue.enqueue("first", async () => {
    events.push("first:start");
    throw new Error("boom");
  });

  const secondOperation = queue.enqueue("second", async () => {
    events.push("second:start");
    events.push("second:end");
    return "second-result";
  });

  await assert.rejects(firstOperation, /boom/);

  const secondResult = await secondOperation;

  assert.equal(secondResult, "second-result");
  assert.deepEqual(events, ["first:start", "second:start", "second:end"]);
  assert.deepEqual(failures, ["first:boom"]);
});
