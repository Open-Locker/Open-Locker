import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import {
  DEFAULT_DELIVERED_COMMAND_RETENTION_MS,
  FileDedupStore,
} from '../../src/adapters/mqtt/dedup-store';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';
import { PersistentStateCorruptedError } from '../../src/infrastructure/file-persistence';

const supportsUnixModes = process.platform !== 'win32';

test(
  'existing dedup and response state permissions are hardened on read',
  { skip: !supportsUnixModes },
  (t) => {
    const file = createStoreFile(t);
    fs.writeFileSync(file, JSON.stringify({ version: 2, seenMessageIds: {}, commandRecords: {} }), {
      mode: 0o644,
    });
    fs.chmodSync(file, 0o644);

    new FileDedupStore(file).assertHealthy();

    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
  },
);

test('completed response survives a store restart and can be sent', async (t) => {
  const file = createStoreFile(t);
  const store = new FileDedupStore(file);
  store.markCommandCompleted('txn-completed', 'open_compartment', {
    result: 'success',
    message: 'Compartment opened.',
  });

  const restartedStore = new FileDedupStore(file);
  const record = restartedStore.getCommandRecord('txn-completed');
  const persisted = JSON.parse(fs.readFileSync(file, 'utf8')) as {
    commandRecords: Record<string, { response: Record<string, unknown> }>;
  };

  assert.equal(record?.status, 'completed');
  assert.deepEqual(record?.response, {
    result: 'success',
    message: 'Compartment opened.',
  });
  assert.deepEqual(persisted.commandRecords['txn-completed']?.response, {
    result: 'success',
    message: 'Compartment opened.',
  });
  assert.equal(record?.responseDeliveredAt, undefined);
  assert.deepEqual(
    restartedStore.listCommandRecords().map(({ transactionId }) => transactionId),
    ['txn-completed'],
  );

  const published: string[] = [];
  const dispatcher = new CommandDispatcher(
    new InboundProtocolGuard(restartedStore),
    new OutboundMqttAdapter(async (_topic, payload) => {
      published.push(payload);
    }, 'locker/test/response'),
    restartedStore,
  );
  await dispatcher.flushPendingResponses();

  assert.equal(published.length, 1);
  const replayed = JSON.parse(published[0]!) as Record<string, unknown>;
  assert.equal(replayed.action, 'open_compartment');
  assert.equal(replayed.result, 'success');
  assert.equal(replayed.transaction_id, 'txn-completed');
  assert.equal(replayed.message, 'Compartment opened.');
  assert.equal(typeof replayed.message_id, 'string');
  assert.equal(typeof replayed.timestamp, 'string');
  assert.ok(restartedStore.getCommandRecord('txn-completed')?.responseDeliveredAt);
});

test('in_progress record survives a store restart', (t) => {
  const file = createStoreFile(t);
  new FileDedupStore(file).markCommandInProgress('txn-interrupted', 'open_compartment');

  const record = new FileDedupStore(file).getCommandRecord('txn-interrupted');

  assert.equal(record?.status, 'in_progress');
  assert.equal(record?.action, 'open_compartment');
});

test('legacy completed records without responses migrate explicitly and remain safe', (t) => {
  const file = createStoreFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      seenMessageIds: {
        'msg-legacy': '2026-04-11T10:00:00.000Z',
      },
      commandRecords: {
        'txn-legacy': {
          action: 'open_compartment',
          status: 'completed',
          updatedAt: '2026-04-11T10:00:01.000Z',
        },
      },
    }),
    'utf8',
  );

  const store = new FileDedupStore(file, {
    now: () => new Date('2026-04-20T00:00:00.000Z'),
  });

  assert.equal(store.hasSeenMessageId('msg-legacy'), true);
  assert.deepEqual(store.getCommandRecord('txn-legacy'), {
    action: 'open_compartment',
    status: 'completed',
    updatedAt: '2026-04-11T10:00:01.000Z',
    legacyResponseUnavailable: true,
  });
  const persisted = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.equal(persisted.version, 2);
  assert.equal(persisted.commandRecords['txn-legacy'].legacyResponseUnavailable, true);
});

test('legacy full responses migrate and replay with reconstructed identity', async (t) => {
  const file = createStoreFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      seenMessageIds: {},
      commandRecords: {
        'txn-legacy-response': {
          action: 'apply_config',
          status: 'completed',
          updatedAt: '2026-04-11T10:00:01.000Z',
          response: {
            action: 'apply_config',
            result: 'success',
            transaction_id: 'txn-legacy-response',
            applied_config_hash: 'abc123',
          },
        },
      },
    }),
    'utf8',
  );

  const store = new FileDedupStore(file);
  store.assertHealthy();

  assert.deepEqual(store.getCommandRecord('txn-legacy-response')?.response, {
    result: 'success',
    applied_config_hash: 'abc123',
  });
  const persisted = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.deepEqual(persisted.commandRecords['txn-legacy-response'].response, {
    result: 'success',
    applied_config_hash: 'abc123',
  });

  const published: string[] = [];
  const dispatcher = new CommandDispatcher(
    new InboundProtocolGuard(store),
    new OutboundMqttAdapter(async (_topic, payload) => {
      published.push(payload);
    }, 'locker/test/response'),
    store,
  );
  await dispatcher.flushPendingResponses();

  const replayed = JSON.parse(published[0]!) as Record<string, unknown>;
  assert.equal(replayed.transaction_id, 'txn-legacy-response');
  assert.equal(replayed.action, 'apply_config');
  assert.equal(replayed.applied_config_hash, 'abc123');
});

test('legacy responses with conflicting identity fail closed', (t) => {
  const file = createStoreFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      seenMessageIds: {},
      commandRecords: {
        'txn-map-key': {
          action: 'open_compartment',
          status: 'completed',
          updatedAt: '2026-04-11T10:00:01.000Z',
          response: {
            action: 'apply_config',
            result: 'success',
            transaction_id: 'txn-other',
          },
        },
      },
    }),
    'utf8',
  );

  assert.throws(
    () => new FileDedupStore(file).assertHealthy(),
    (error: unknown) => error instanceof PersistentStateCorruptedError,
  );
});

test('version 2 rejects redundant and semantically invalid command records', (t) => {
  const invalidRecords = [
    {
      action: 'open_compartment',
      status: 'completed',
      updatedAt: '2026-04-11T10:00:01.000Z',
      response: {
        action: 'open_compartment',
        result: 'success',
        transaction_id: 'txn-invalid',
      },
    },
    {
      action: 'open_compartment',
      status: 'completed',
      updatedAt: '2026-04-11T10:00:01.000Z',
    },
    {
      action: 'open_compartment',
      status: 'in_progress',
      updatedAt: '2026-04-11T10:00:01.000Z',
      response: { result: 'success' },
    },
    {
      action: 'open_compartment',
      status: 'in_progress',
      updatedAt: '2026-04-11T10:00:01.000Z',
      responseDeliveredAt: '2026-04-11T10:00:02.000Z',
    },
  ];

  for (const [index, record] of invalidRecords.entries()) {
    const file = createStoreFile(t);
    fs.writeFileSync(
      file,
      JSON.stringify({
        version: 2,
        seenMessageIds: {},
        commandRecords: { [`txn-invalid-${index}`]: record },
      }),
      'utf8',
    );

    assert.throws(
      () => new FileDedupStore(file).assertHealthy(),
      (error: unknown) => error instanceof PersistentStateCorruptedError,
    );
  }
});

test('corrupt dedup state fails closed and remains untouched', (t) => {
  const file = createStoreFile(t);
  const corruptContents = '{"seenMessageIds":';
  fs.writeFileSync(file, corruptContents, 'utf8');

  const store = new FileDedupStore(file);

  assert.throws(
    () => store.assertHealthy(),
    (error: unknown) =>
      error instanceof PersistentStateCorruptedError &&
      error.stateType === 'MQTT deduplication and response state',
  );
  assert.equal(fs.readFileSync(file, 'utf8'), corruptContents);
});

test('seen message IDs are pruned by TTL and deterministic maximum size', (t) => {
  const file = createStoreFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      seenMessageIds: {
        expired: '2026-01-01T00:00:00.000Z',
        oldest: '2026-04-20T00:00:00.000Z',
        newerB: '2026-04-25T00:00:00.000Z',
        newerA: '2026-04-25T00:00:00.000Z',
      },
      commandRecords: {},
    }),
    'utf8',
  );

  const store = new FileDedupStore(file, {
    now: () => new Date('2026-05-01T00:00:00.000Z'),
    seenMessageIdTtlMs: 15 * 24 * 60 * 60 * 1000,
    maxSeenMessageIds: 2,
  });
  store.assertHealthy();

  assert.equal(store.hasSeenMessageId('expired'), false);
  assert.equal(store.hasSeenMessageId('oldest'), false);
  assert.equal(store.hasSeenMessageId('newerA'), true);
  assert.equal(store.hasSeenMessageId('newerB'), true);
});

test('pending, undelivered, and in-progress command records are never pruned', (t) => {
  const file = createStoreFile(t);
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 2,
      seenMessageIds: {},
      commandRecords: {
        'txn-pending': {
          action: 'open_compartment',
          status: 'completed',
          updatedAt: '2025-01-01T00:00:00.000Z',
          response: {
            result: 'success',
          },
        },
        'txn-legacy': {
          action: 'open_compartment',
          status: 'completed',
          updatedAt: '2025-01-01T00:00:00.000Z',
          legacyResponseUnavailable: true,
        },
        'txn-unknown': {
          action: 'open_compartment',
          status: 'in_progress',
          updatedAt: '2025-01-01T00:00:00.000Z',
        },
      },
    }),
    'utf8',
  );

  const store = new FileDedupStore(file, {
    now: () => new Date('2026-05-01T00:00:00.000Z'),
  });
  store.assertHealthy();

  assert.deepEqual(
    store.listCommandRecords().map(({ transactionId }) => transactionId),
    ['txn-pending', 'txn-legacy', 'txn-unknown'],
  );
});

test('delivered completed records are pruned only after retention', (t) => {
  const file = createStoreFile(t);
  const now = new Date('2026-05-01T00:00:00.000Z');
  const justWithinRetention = new Date(
    now.getTime() - DEFAULT_DELIVERED_COMMAND_RETENTION_MS + 1,
  ).toISOString();
  const beyondRetention = new Date(
    now.getTime() - DEFAULT_DELIVERED_COMMAND_RETENTION_MS - 1,
  ).toISOString();
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 2,
      seenMessageIds: {},
      commandRecords: {
        retained: deliveredRecord('retained', justWithinRetention),
        pruned: deliveredRecord('pruned', beyondRetention),
      },
    }),
    'utf8',
  );

  const store = new FileDedupStore(file, { now: () => now });
  store.assertHealthy();

  assert.ok(store.getCommandRecord('retained'));
  assert.equal(store.getCommandRecord('pruned'), null);
});

test('write failures leave the cached state unchanged', (t) => {
  const messageFile = createStoreFile(t);
  const messageStore = new FileDedupStore(messageFile);
  messageStore.rememberMessageId('msg-existing');
  replaceFileWithDirectory(messageFile);

  assert.throws(() => messageStore.rememberMessageId('msg-failed'));
  assert.equal(messageStore.hasSeenMessageId('msg-existing'), true);
  assert.equal(messageStore.hasSeenMessageId('msg-failed'), false);

  const commandFile = createStoreFile(t);
  const commandStore = new FileDedupStore(commandFile);
  commandStore.markCommandCompleted('txn-pending', 'open_compartment', {
    result: 'success',
  });
  const stateBeforeFailedWrite = commandStore.getCommandRecord('txn-pending');
  replaceFileWithDirectory(commandFile);

  assert.throws(() => commandStore.markCommandResponseDelivered('txn-pending'));
  assert.deepEqual(commandStore.getCommandRecord('txn-pending'), stateBeforeFailedWrite);
  assert.equal(commandStore.getCommandRecord('txn-pending')?.responseDeliveredAt, undefined);
});

function createStoreFile(t: TestContext): string {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'open-locker-dedup-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  return path.join(directory, 'dedup.json');
}

function replaceFileWithDirectory(file: string): void {
  fs.rmSync(file);
  fs.mkdirSync(file);
}

function deliveredRecord(_transactionId: string, deliveredAt: string) {
  return {
    action: 'open_compartment',
    status: 'completed',
    updatedAt: deliveredAt,
    response: {
      result: 'success',
    },
    responseDeliveredAt: deliveredAt,
  };
}
