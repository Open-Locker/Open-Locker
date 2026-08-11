import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, type TestContext } from 'node:test';
import { CommandDispatcher } from '../../src/adapters/mqtt/command-dispatcher';
import { FileDedupStore } from '../../src/adapters/mqtt/dedup-store';
import { InboundProtocolGuard } from '../../src/adapters/mqtt/inbound-protocol-guard';
import { OutboundMqttAdapter } from '../../src/adapters/mqtt/outbound-mqtt.adapter';

test('completed response survives a store restart and can be sent', async (t) => {
  const file = createStoreFile(t);
  const store = new FileDedupStore(file);
  store.markCommandCompleted('txn-completed', 'open_compartment', {
    action: 'open_compartment',
    result: 'success',
    transaction_id: 'txn-completed',
    message: 'Compartment opened.',
  });

  const restartedStore = new FileDedupStore(file);
  const record = restartedStore.getCommandRecord('txn-completed');

  assert.equal(record?.status, 'completed');
  assert.equal(record?.response?.transaction_id, 'txn-completed');
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
  assert.ok(restartedStore.getCommandRecord('txn-completed')?.responseDeliveredAt);
});

test('in_progress record survives a store restart', (t) => {
  const file = createStoreFile(t);
  new FileDedupStore(file).markCommandInProgress('txn-interrupted', 'open_compartment');

  const record = new FileDedupStore(file).getCommandRecord('txn-interrupted');

  assert.equal(record?.status, 'in_progress');
  assert.equal(record?.action, 'open_compartment');
});

test('legacy dedup files remain readable without response fields', (t) => {
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

  const store = new FileDedupStore(file);

  assert.equal(store.hasSeenMessageId('msg-legacy'), true);
  assert.deepEqual(store.getCommandRecord('txn-legacy'), {
    action: 'open_compartment',
    status: 'completed',
    updatedAt: '2026-04-11T10:00:01.000Z',
  });
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
    action: 'open_compartment',
    result: 'success',
    transaction_id: 'txn-pending',
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
