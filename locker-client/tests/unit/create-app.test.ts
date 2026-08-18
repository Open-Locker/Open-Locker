import assert from 'node:assert/strict';
import { test } from 'node:test';
import { dispatchMqttMessage } from '../../src/bootstrap/createApp';

test('MQTT message boundary logs unexpected dispatcher rejections without payload', async () => {
  const logged: Array<{ message: string; metadata?: Record<string, unknown> }> = [];
  const dispatcher = {
    async dispatch(): Promise<void> {
      throw new Error('dispatch exploded');
    },
  };
  const log = {
    error(message: string, metadata?: Record<string, unknown>) {
      logged.push({ message, metadata });
    },
  };

  dispatchMqttMessage(
    dispatcher,
    'locker/test/command',
    'locker/test/command',
    Buffer.from('secret command payload'),
    log,
  );
  await new Promise((resolve) => setImmediate(resolve));

  assert.deepEqual(logged, [
    {
      message: 'Unexpected MQTT command dispatch failure',
      metadata: { error: 'dispatch exploded' },
    },
  ]);
  assert.equal(JSON.stringify(logged).includes('secret command payload'), false);
});
