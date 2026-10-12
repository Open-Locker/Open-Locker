#!/usr/bin/env node
/*
 * Checks a firmware device through the real broker, the way the backend talks
 * to it (hardware checks HW-C3 and HW-C5).
 *
 *     BROKER_USER=... BROKER_PASSWORD=... node scripts/broker-check.mjs \
 *         --url mqtts://broker.example:8883 --locker-uuid <uuid> \
 *         [--compartments 1:1:0,2:1:1] [--feedback door_closing] [--heartbeat 15]
 *
 * It sends `apply_config` and checks that the response carries the hash it
 * computed. That releases nothing.
 *
 * With --actuate it then sends one `open_compartment` (--compartment N) and
 * repeats that command twice, once with the same message id and once with a
 * new one. The lock moves ONCE, if the device is right. The tool reports what
 * came back on MQTT; compare the unlock transmission counter of the device
 * (`diagnostics` on the console) before and after, which must differ by one.
 *
 * The credentials are read from the environment, so they do not show up in a
 * process list. Needs the `mqtt` package (`pnpm install` in locker-firmware/).
 */

import { createHash, randomUUID } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const WAIT_MS = 15000;
const QUIET_MS = 6000;

/** The mapping of "number:board:address,..." as the objects the contract uses. */
export function parseCompartments(text) {
  return text.split(',').map((item) => {
    const [number, slaveId, address] = item.split(':').map(Number);
    if (![number, slaveId, address].every(Number.isInteger)) {
      throw new Error(`"${item}" is not number:board:address`);
    }
    return { compartment_number: number, slaveId, address };
  });
}

/** The compact JSON whose SHA-256 is the config hash (ADR-0061). */
export function canonicalConfig(feedbackType, compartments) {
  const sorted = [...compartments].sort((a, b) => a.compartment_number - b.compartment_number);
  return JSON.stringify({
    adapter_type: 'rs485_lock_board',
    feedback_type: feedbackType,
    compartments: sorted.map(({ compartment_number, slaveId, address }) => ({
      compartment_number,
      slaveId,
      address,
    })),
  });
}

export function configHash(feedbackType, compartments) {
  return createHash('sha256').update(canonicalConfig(feedbackType, compartments)).digest('hex');
}

export function applyConfigCommand({ transactionId, feedbackType, heartbeat, compartments }) {
  return {
    message_id: randomUUID(),
    transaction_id: transactionId,
    action: 'apply_config',
    timestamp: new Date().toISOString(),
    data: {
      config_hash: configHash(feedbackType, compartments),
      heartbeat_interval_seconds: heartbeat,
      adapter_type: 'rs485_lock_board',
      feedback_type: feedbackType,
      compartments,
    },
  };
}

export function openCommand({ transactionId, messageId, compartment }) {
  return {
    message_id: messageId,
    transaction_id: transactionId,
    action: 'open_compartment',
    timestamp: new Date().toISOString(),
    data: { compartment_number: compartment },
  };
}

function option(args, name, fallback) {
  const index = args.indexOf(name);
  return index >= 0 ? args[index + 1] : fallback;
}

async function main(args) {
  const url = option(args, '--url');
  const uuid = option(args, '--locker-uuid');
  const user = process.env.BROKER_USER;
  const password = process.env.BROKER_PASSWORD;
  if (!url || !uuid || !user || !password) {
    console.error(
      'usage: BROKER_USER=... BROKER_PASSWORD=... node scripts/broker-check.mjs --url mqtts://host:port --locker-uuid <uuid> [--compartments 1:1:0,2:1:1] [--feedback door_closing|door_opening] [--heartbeat 15] [--actuate --compartment N]',
    );
    return 2;
  }
  const actuate = args.includes('--actuate');
  const compartments = parseCompartments(option(args, '--compartments', '1:1:0'));
  const feedbackType = option(args, '--feedback', 'door_closing');
  const heartbeat = Number(option(args, '--heartbeat', '15'));
  const compartment = Number(option(args, '--compartment', String(compartments[0].compartment_number)));

  let mqtt;
  try {
    mqtt = (await import('mqtt')).default;
  } catch {
    console.error('The mqtt package is missing. Run `pnpm install` in locker-firmware/.');
    return 2;
  }

  // Certificate and host name are checked; there is no flag to turn that off.
  const client = mqtt.connect(url, {
    username: user,
    password,
    clientId: `broker-check-${randomUUID()}`,
    rejectUnauthorized: true,
  });
  const responses = [];
  client.on('message', (_topic, payload) => {
    try {
      responses.push({ at: Date.now(), body: JSON.parse(payload.toString('utf8')) });
    } catch {
      // Not ours to judge.
    }
  });
  await new Promise((resolve, reject) => {
    client.once('connect', resolve);
    client.once('error', reject);
  });
  await client.subscribeAsync(`locker/${uuid}/response`, { qos: 1 });
  const command = `locker/${uuid}/command`;

  const waitFor = async (transactionId, timeoutMs) => {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const found = responses.find((response) => response.body.transaction_id === transactionId);
      if (found) return found.body;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    return null;
  };

  let failed = false;
  const fail = (text) => {
    console.log(`FAIL ${text}`);
    failed = true;
  };

  const configTransaction = `broker-check-config-${randomUUID()}`;
  await client.publishAsync(
    command,
    JSON.stringify(applyConfigCommand({ transactionId: configTransaction, feedbackType, heartbeat, compartments })),
    { qos: 1 },
  );
  const applied = await waitFor(configTransaction, WAIT_MS);
  if (applied === null) {
    fail('apply_config: no response');
  } else if (applied.result !== 'success') {
    fail(`apply_config: ${applied.error_code ?? applied.result}`);
  } else if (applied.applied_config_hash !== configHash(feedbackType, compartments)) {
    fail('apply_config: the applied hash differs from the one computed here');
  } else {
    console.log('PASS apply_config acknowledged with the matching hash');
  }

  if (actuate && !failed) {
    const transactionId = `broker-check-open-${randomUUID()}`;
    const messageId = randomUUID();
    await client.publishAsync(command, JSON.stringify(openCommand({ transactionId, messageId, compartment })), { qos: 1 });
    const first = await waitFor(transactionId, WAIT_MS);
    if (first === null || first.result !== 'success') {
      fail(`open_compartment: ${first === null ? 'no response' : (first.error_code ?? first.result)}`);
    } else {
      const before = responses.length;
      // Same message id: must be ignored. New message id, same transaction: the stored answer.
      await client.publishAsync(command, JSON.stringify(openCommand({ transactionId, messageId, compartment })), { qos: 1 });
      await client.publishAsync(command, JSON.stringify(openCommand({ transactionId, messageId: randomUUID(), compartment })), { qos: 1 });
      await new Promise((resolve) => setTimeout(resolve, QUIET_MS));
      const extra = responses.slice(before).filter((r) => r.body.transaction_id === transactionId);
      console.log(`INFO the duplicates produced ${extra.length} response(s); expected 1 (the replay)`);
      if (extra.length > 1) fail('a duplicate command was answered more than once');
      console.log('NOW  compare `diagnostics` of the device: unlock_transmissions must have grown by exactly 1');
    }
  }

  await client.endAsync();
  console.log(failed ? 'FAILED' : 'PASSED');
  return failed ? 1 : 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  process.exit(await main(process.argv.slice(2)));
}
