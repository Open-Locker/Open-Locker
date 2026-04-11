import assert from "node:assert/strict";
import test from "node:test";
import { MQTTMessageHandler } from "../mqtt/mqttMessageHandler";
import { mqttService } from "../services/mqttService";
import { mqttDedupService } from "../services/mqttDedupService";

type CommandRecord = {
  action: string;
  status: "in_progress" | "completed";
  updatedAt: string;
};

type PublishedMessage = {
  topic: string;
  message: Record<string, unknown>;
  options?: { qos?: 0 | 1 | 2; retain?: boolean };
};

function createDedupMock() {
  const seenMessageIds = new Set<string>();
  const commandRecords = new Map<string, CommandRecord>();

  return {
    hasSeenMessageId(messageId: string) {
      return seenMessageIds.has(messageId);
    },
    rememberMessageId(messageId: string) {
      seenMessageIds.add(messageId);
    },
    getCommandRecord(transactionId: string) {
      return commandRecords.get(transactionId) ?? null;
    },
    markCommandInProgress(transactionId: string, action: string) {
      commandRecords.set(transactionId, {
        action,
        status: "in_progress",
        updatedAt: new Date().toISOString(),
      });
    },
    markCommandCompleted(transactionId: string, action: string) {
      commandRecords.set(transactionId, {
        action,
        status: "completed",
        updatedAt: new Date().toISOString(),
      });
    },
  };
}

function createHandlerHarness() {
  let openCompartmentImpl: (compartmentID: number) => Promise<void> = async () => {};
  const handler = new MQTTMessageHandler(() => ({
    handleOpenCompartment: openCompartmentImpl,
  }));
  const publishedMessages: PublishedMessage[] = [];
  const dedupMock = createDedupMock();
  const originalPublish = mqttService.publish.bind(mqttService);
  const originalHasSeenMessageId = mqttDedupService.hasSeenMessageId.bind(
    mqttDedupService,
  );
  const originalRememberMessageId = mqttDedupService.rememberMessageId.bind(
    mqttDedupService,
  );
  const originalGetCommandRecord = mqttDedupService.getCommandRecord.bind(
    mqttDedupService,
  );
  const originalMarkCommandInProgress =
    mqttDedupService.markCommandInProgress.bind(mqttDedupService);
  const originalMarkCommandCompleted =
    mqttDedupService.markCommandCompleted.bind(mqttDedupService);

  (handler as any).lockerUuid = "locker-test";

  mqttService.publish = async (topic, message, options) => {
    publishedMessages.push({
      topic,
      message: message as Record<string, unknown>,
      options,
    });
  };

  mqttDedupService.hasSeenMessageId = dedupMock.hasSeenMessageId;
  mqttDedupService.rememberMessageId = dedupMock.rememberMessageId;
  mqttDedupService.getCommandRecord = dedupMock.getCommandRecord;
  mqttDedupService.markCommandInProgress = dedupMock.markCommandInProgress;
  mqttDedupService.markCommandCompleted = dedupMock.markCommandCompleted;

  return {
    handler,
    publishedMessages,
    setOpenCompartmentMock(implementation: (compartmentID: number) => Promise<void>) {
      openCompartmentImpl = implementation;
    },
    async handleCommand(command: Record<string, unknown>) {
      await (handler as any).handleCommand(JSON.stringify(command));
    },
    restore() {
      mqttService.publish = originalPublish;
      mqttDedupService.hasSeenMessageId = originalHasSeenMessageId;
      mqttDedupService.rememberMessageId = originalRememberMessageId;
      mqttDedupService.getCommandRecord = originalGetCommandRecord;
      mqttDedupService.markCommandInProgress = originalMarkCommandInProgress;
      mqttDedupService.markCommandCompleted = originalMarkCommandCompleted;
    },
  };
}

test("first valid open_compartment command executes once and publishes success", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;

  harness.setOpenCompartmentMock(async (compartmentNumber) => {
    openCount++;
    assert.equal(compartmentNumber, 3);
  });

  try {
    await harness.handleCommand({
      action: "open_compartment",
      transaction_id: "txn-1",
      message_id: "msg-1",
      timestamp: "2026-04-11T10:00:00Z",
      data: { compartment_number: 3 },
    });

    assert.equal(openCount, 1);
    assert.equal(harness.publishedMessages.length, 1);
    assert.equal(
      harness.publishedMessages[0]?.topic,
      "locker/locker-test/response",
    );
    assert.equal(
      harness.publishedMessages[0]?.message.result,
      "success",
    );
    assert.equal(
      harness.publishedMessages[0]?.message.transaction_id,
      "txn-1",
    );
  } finally {
    harness.restore();
  }
});

test("duplicate message_id is ignored before any hardware side effects", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;

  harness.setOpenCompartmentMock(async () => {
    openCount++;
  });

  try {
    const firstCommand = {
      action: "open_compartment",
      transaction_id: "txn-2",
      message_id: "msg-dup",
      timestamp: "2026-04-11T10:00:00Z",
      data: { compartment_number: 2 },
    };

    await harness.handleCommand(firstCommand);
    await harness.handleCommand({
      ...firstCommand,
      data: { compartment_number: 7 },
    });

    assert.equal(openCount, 1);
    assert.equal(harness.publishedMessages.length, 1);
  } finally {
    harness.restore();
  }
});

test("duplicate transaction_id is ignored after completion without re-ACKing", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;

  harness.setOpenCompartmentMock(async () => {
    openCount++;
  });

  try {
    await harness.handleCommand({
      action: "open_compartment",
      transaction_id: "txn-3",
      message_id: "msg-3a",
      timestamp: "2026-04-11T10:00:00Z",
      data: { compartment_number: 4 },
    });

    await harness.handleCommand({
      action: "open_compartment",
      transaction_id: "txn-3",
      message_id: "msg-3b",
      timestamp: "2026-04-11T10:00:05Z",
      data: { compartment_number: 4 },
    });

    assert.equal(openCount, 1);
    assert.equal(harness.publishedMessages.length, 1);
  } finally {
    harness.restore();
  }
});

test("duplicate transaction_id is ignored while the first execution is in progress", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;
  let releaseFirstExecution!: () => void;

  harness.setOpenCompartmentMock(
    (async () => {
      openCount++;
      await new Promise<void>((resolve) => {
        releaseFirstExecution = resolve;
      });
    }) as (compartmentID: number) => Promise<void>,
  );

  try {
    const firstExecution = harness.handleCommand({
      action: "open_compartment",
      transaction_id: "txn-4",
      message_id: "msg-4a",
      timestamp: "2026-04-11T10:00:00Z",
      data: { compartment_number: 1 },
    });

    await Promise.resolve();

    await harness.handleCommand({
      action: "open_compartment",
      transaction_id: "txn-4",
      message_id: "msg-4b",
      timestamp: "2026-04-11T10:00:01Z",
      data: { compartment_number: 1 },
    });

    assert.equal(openCount, 1);
    assert.equal(harness.publishedMessages.length, 0);

    releaseFirstExecution();
    await firstExecution;

    assert.equal(harness.publishedMessages.length, 1);
    assert.equal(
      harness.publishedMessages[0]?.message.transaction_id,
      "txn-4",
    );
  } finally {
    harness.restore();
  }
});

test("missing or empty transaction_id is rejected without side effects", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;

  harness.setOpenCompartmentMock(async () => {
    openCount++;
  });

  try {
    await harness.handleCommand({
      action: "open_compartment",
      transaction_id: "   ",
      message_id: "msg-5",
      timestamp: "2026-04-11T10:00:00Z",
      data: { compartment_number: 5 },
    });

    assert.equal(openCount, 0);
    assert.equal(harness.publishedMessages.length, 0);
  } finally {
    harness.restore();
  }
});

test("apply_config uses the transaction-aware path and returns a deterministic error response", async () => {
  const harness = createHandlerHarness();
  let openCount = 0;

  harness.setOpenCompartmentMock(async () => {
    openCount++;
  });

  try {
    await harness.handleCommand({
      action: "apply_config",
      transaction_id: "txn-6",
      message_id: "msg-6",
      timestamp: "2026-04-11T10:00:00Z",
      data: {
        config_hash: "a".repeat(64),
        heartbeat_interval_seconds: 15,
        compartments: [{ id: 1, slaveId: 1, address: 0 }],
      },
    });

    assert.equal(openCount, 0);
    assert.equal(harness.publishedMessages.length, 1);
    assert.equal(harness.publishedMessages[0]?.message.action, "apply_config");
    assert.equal(harness.publishedMessages[0]?.message.result, "error");
    assert.equal(
      harness.publishedMessages[0]?.message.message,
      "Action apply_config is not implemented yet.",
    );
  } finally {
    harness.restore();
  }
});
