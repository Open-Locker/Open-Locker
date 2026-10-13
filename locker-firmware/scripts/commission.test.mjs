/*
 * Tests for the commissioning tool, scripts/commission.mjs. Run with
 * `node --test scripts/`.
 *
 * The tool is driven through three objects, so it can be tested without a
 * serial port or a terminal:
 *
 *   commission({ serial, prompt, output }) -> Promise<{ outcome }>
 *
 *   serial.exchange(line)   sends one console line and resolves with the
 *                           device's reply object, or rejects when the
 *                           connection is lost
 *   prompt.ask(label)       asks for a value that may be shown
 *   prompt.askSecret(label) asks for a value without echo
 *   output.write(text)      everything the tool prints
 *
 * `outcome` is 'saved', 'refused', 'connection_lost' or 'no_reply'.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs';
import { mock, test } from 'node:test';

import { commission } from './commission.mjs';

const ANSWERS = {
  ssid: 'workshop-network',
  wifiPassword: 'S3CRET-WIFI-PASSWORD',
  broker: 'mqtts://broker.example:8883',
  bootstrapUser: 'bootstrap-user',
  bootstrapPassword: 'S3CRET-BOOTSTRAP-PASSWORD',
  token: 'S3CRET-ONE-TIME-TOKEN',
};
const SECRETS = [ANSWERS.wifiPassword, ANSWERS.bootstrapPassword, ANSWERS.token];

function answerFor(label) {
  const text = label.toLowerCase();
  if (text.includes('token')) return ANSWERS.token;
  if (text.includes('password')) {
    return text.includes('wi-fi') || text.includes('wifi')
      ? ANSWERS.wifiPassword
      : ANSWERS.bootstrapPassword;
  }
  if (text.includes('ssid') || text.includes('network')) return ANSWERS.ssid;
  if (text.includes('broker')) return ANSWERS.broker;
  return ANSWERS.bootstrapUser;
}

/** A device that answers `status` as not enrolled and `configure` with `configureReply`. */
function harness(configureReply) {
  const sent = [];
  const shown = [];
  const hidden = [];
  const printed = [];
  return {
    sent,
    shown,
    hidden,
    printed,
    serial: {
      async exchange(line) {
        sent.push(line);
        if (line.startsWith('configure ')) {
          if (configureReply instanceof Error) throw configureReply;
          return configureReply;
        }
        return { status: 'ready', enrolled: false, pending: false, configured: false };
      },
    },
    prompt: {
      async ask(label) {
        shown.push(label);
        return answerFor(label);
      },
      async askSecret(label) {
        hidden.push(label);
        return answerFor(label);
      },
    },
    output: {
      write(text) {
        printed.push(String(text));
      },
    },
  };
}

const run = ({ serial, prompt, output }) => commission({ serial, prompt, output });

test('S5-H16 secrets are read without echo and never written to disk or to output', async () => {
  const writers = [
    mock.method(fs, 'writeFileSync', () => {}),
    mock.method(fs, 'appendFileSync', () => {}),
    mock.method(fs, 'createWriteStream', () => {
      throw new Error('the tool must not open a file for writing');
    }),
    mock.method(fs.promises, 'writeFile', async () => {}),
    mock.method(fs.promises, 'appendFile', async () => {}),
  ];
  const device = harness({ status: 'saved', enrolled: false, pending: false, configured: true });

  const result = await run(device);

  assert.equal(result.outcome, 'saved');
  // Every secret was asked for without echo, and only that way.
  assert.equal(device.hidden.length, 3);
  for (const label of device.shown) {
    assert.doesNotMatch(label, /password|token/i);
  }
  // The device received them; nothing else did.
  const configure = device.sent.find((line) => line.startsWith('configure '));
  assert.ok(configure, 'a configure line was sent');
  const submitted = JSON.parse(configure.slice('configure '.length));
  assert.equal(submitted.ssid, ANSWERS.ssid);
  assert.equal(submitted.wifi_password, ANSWERS.wifiPassword);
  assert.equal(submitted.broker, ANSWERS.broker);
  assert.equal(submitted.bootstrap_user, ANSWERS.bootstrapUser);
  assert.equal(submitted.bootstrap_password, ANSWERS.bootstrapPassword);
  assert.equal(submitted.token, ANSWERS.token);
  const printed = device.printed.join('');
  for (const secret of SECRETS) {
    assert.ok(!printed.includes(secret), 'a secret was printed');
  }
  for (const writer of writers) {
    assert.equal(writer.mock.callCount(), 0, 'the tool wrote to disk');
  }
  mock.restoreAll();
});

test('S5-H17 a lost serial connection after sending is reported and not resent', async () => {
  const device = harness(new Error('serial port closed'));

  const result = await run(device);

  assert.equal(result.outcome, 'connection_lost');
  const configures = device.sent.filter((line) => line.startsWith('configure '));
  assert.equal(configures.length, 1, 'the settings were sent more than once');
  // Nothing is sent after the loss either: the operator checks the status first.
  assert.equal(device.sent.at(-1), configures[0]);
  const printed = device.printed.join('');
  assert.match(printed, /status/i);
  for (const secret of SECRETS) {
    assert.ok(!printed.includes(secret), 'a secret was printed');
  }
});

test('S5-H18 the tool never issues a reset on its own', async () => {
  const replies = [
    { status: 'saved', enrolled: false, pending: false, configured: true },
    { status: 'invalid_settings', enrolled: false, pending: false, configured: false },
    { status: 'reset_required', enrolled: false, pending: true, configured: true },
    { status: 'save_failed', enrolled: false, pending: false, configured: false },
    new Error('serial port closed'),
  ];

  for (const reply of replies) {
    const device = harness(reply);
    const result = await run(device);
    if (!(reply instanceof Error) && reply.status !== 'saved') {
      assert.equal(result.outcome, 'refused');
    }
    for (const line of device.sent) {
      assert.doesNotMatch(line, /^reset/i, `the tool sent "${line.split(' ')[0]}"`);
    }
  }
});

test('a device that does not answer is reported, not thrown', async () => {
  const device = harness({ status: 'saved' });
  device.serial.exchange = async (line) => {
    device.sent.push(line);
    throw new Error('no reply');
  };

  const result = await run(device);

  assert.equal(result.outcome, 'no_reply');
  assert.deepEqual(device.sent, ['status']);
  assert.match(device.printed.join(''), /no reply/i);
});
