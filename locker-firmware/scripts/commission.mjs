#!/usr/bin/env node
/*
 * Commissioning tool for the locker firmware: gives a device its network
 * settings and, before enrollment, the bootstrap credentials and the one-time
 * provisioning token, over the USB console.
 *
 *     node scripts/commission.mjs --port COM3
 *     node scripts/commission.mjs --port /dev/ttyUSB0
 *
 * Secrets are read without echo. They go to the device and nowhere else: they
 * are not printed, logged, or written to a file. The tool sends the settings
 * once and never resends them, and it never resets the device; a reset is a
 * deliberate act of the operator (`reset-network` in the console).
 *
 * Needs the `serialport` package (`npm install` in locker-firmware/) only when
 * run from the command line. The commissioning logic below uses nothing but
 * the three objects it is handed, so it is tested without a serial port.
 */

import { pathToFileURL } from 'node:url';

// The device refuses longer lines (LC_CONSOLE_LINE_MAX).
const LINE_MAX = 1024;
const CONFIGURE_REPLY_TIMEOUT_MS = 20000;

const MESSAGES = {
  saved: 'Settings saved. The device restarts, joins the network and connects to the broker.',
  invalid_settings:
    'The device refused the settings: check the network name, the Wi-Fi password length (8 to 64, or empty), the broker URL (mqtts://...) and that all three enrollment values are given.',
  reset_required:
    'An enrollment is pending on this device, so new settings are refused until it is reset. Run `reset-network` in the device console, then run this tool again with a fresh token.',
  save_failed: 'The device could not store the settings. Nothing was changed on the network side; try again.',
};

/**
 * @param {{ serial: { exchange(line: string): Promise<object> },
 *           prompt: { ask(label: string): Promise<string>, askSecret(label: string): Promise<string> },
 *           output: { write(text: string): void } }} io
 * @returns {Promise<{ outcome: 'saved' | 'refused' | 'connection_lost' }>}
 */
export async function commission({ serial, prompt, output }) {
  const state = await serial.exchange('status');
  output.write(
    `Device: ${state.enrolled ? 'enrolled' : 'not enrolled'}${state.pending ? ', enrollment pending' : ''}${
      state.configured ? ', network settings stored' : ', no network settings'
    }\n`,
  );
  if (state.pending) {
    output.write(`${MESSAGES.reset_required}\n`);
    return { outcome: 'refused' };
  }

  const settings = {
    ssid: await prompt.ask('Wi-Fi network name (SSID)'),
    wifi_password: await prompt.askSecret('Wi-Fi password (empty for an open network)'),
    broker: await prompt.ask('Broker URL (mqtts://host:port)'),
  };
  if (!state.enrolled) {
    // An enrolled device keeps its identity; these would be ignored.
    settings.bootstrap_user = await prompt.ask('Bootstrap user');
    settings.bootstrap_password = await prompt.askSecret('Bootstrap password');
    settings.token = await prompt.askSecret('One-time provisioning token');
  }

  const line = `configure ${JSON.stringify(settings)}`;
  if (Buffer.byteLength(line) >= LINE_MAX) {
    output.write('The settings are too long for the device console. Nothing was sent.\n');
    return { outcome: 'refused' };
  }

  let reply;
  try {
    reply = await serial.exchange(line);
  } catch {
    // Sent once. Whether the device got it is not known, so it is not sent again.
    output.write(
      'The connection was lost after the settings were sent. They may or may not have been saved, and nothing was resent. Reconnect and run `status` before running this tool again.\n',
    );
    return { outcome: 'connection_lost' };
  }

  output.write(`${MESSAGES[reply.status] ?? `The device answered "${reply.status}".`}\n`);
  return { outcome: reply.status === 'saved' ? 'saved' : 'refused' };
}

/* ---- Command line ---- */

/** Turns one console reply line into the object `commission` works with. */
export function parseReply(line) {
  const [word, ...rest] = line.trim().split(/\s+/);
  const fields = Object.fromEntries(
    rest.filter((part) => part.includes('=')).map((part) => part.split('=', 2)),
  );
  if (word === 'ok' && 'bus' in fields) {
    return {
      status: 'ready',
      bus: fields.bus,
      enrolled: fields.enrolled === '1',
      pending: fields.pending === '1',
      configured: fields.configured === '1',
    };
  }
  if (word === 'ok') return { status: rest[0] ?? 'ok' };
  return { status: rest[0] ?? 'error' };
}

/** Reads one line from the keyboard without showing it. */
function readSecret(label) {
  return new Promise((resolve, reject) => {
    const { stdin, stdout } = process;
    if (!stdin.isTTY) {
      reject(new Error('A secret can only be entered on a terminal.'));
      return;
    }
    stdout.write(`${label}: `);
    let value = '';
    stdin.setRawMode(true);
    stdin.resume();
    stdin.setEncoding('utf8');
    const onData = (chunk) => {
      for (const character of chunk) {
        if (character === '\r' || character === '\n') {
          stdin.setRawMode(false);
          stdin.pause();
          stdin.off('data', onData);
          stdout.write('\n');
          resolve(value);
          return;
        }
        if (character === '\u0003') {
          stdin.setRawMode(false);
          stdout.write('\n');
          reject(new Error('Cancelled.'));
          return;
        }
        if (character === '\u007f' || character === '\b') {
          value = value.slice(0, -1);
        } else {
          value += character;
        }
      }
    };
    stdin.on('data', onData);
  });
}

async function main(args) {
  const portIndex = args.indexOf('--port');
  if (portIndex < 0 || !args[portIndex + 1]) {
    console.error('usage: node scripts/commission.mjs --port <serial port> [--baud 115200]');
    return 2;
  }
  const path = args[portIndex + 1];
  const baudRate = Number(args[args.indexOf('--baud') + 1]) || 115200;

  let SerialPort;
  let ReadlineParser;
  try {
    ({ SerialPort } = await import('serialport'));
    ({ ReadlineParser } = await import('@serialport/parser-readline'));
  } catch {
    console.error('The serialport package is missing. Run `npm install` in locker-firmware/.');
    return 2;
  }

  const port = new SerialPort({ path, baudRate });
  const lines = port.pipe(new ReadlineParser({ delimiter: '\n' }));
  const waiting = [];
  lines.on('data', (text) => {
    // Boot logs share the UART; only replies start with ok or error.
    if (/^(ok|error)(\s|$)/.test(text.trim()) && waiting.length > 0) {
      waiting.shift().resolve(parseReply(text));
    }
  });
  port.on('close', () => {
    for (const request of waiting.splice(0)) request.reject(new Error('serial port closed'));
  });

  const serial = {
    exchange(line) {
      return new Promise((resolve, reject) => {
        const request = { resolve, reject };
        waiting.push(request);
        const timer = setTimeout(() => reject(new Error('no reply')), CONFIGURE_REPLY_TIMEOUT_MS);
        request.resolve = (value) => {
          clearTimeout(timer);
          resolve(value);
        };
        port.write(`${line}\n`, (error) => {
          if (error) reject(error);
        });
      });
    },
  };
  const prompt = {
    async ask(label) {
      const { createInterface } = await import('node:readline/promises');
      const rl = createInterface({ input: process.stdin, output: process.stdout });
      try {
        return (await rl.question(`${label}: `)).trim();
      } finally {
        rl.close();
      }
    },
    askSecret: readSecret,
  };

  try {
    const { outcome } = await commission({ serial, prompt, output: process.stdout });
    return outcome === 'saved' ? 0 : 1;
  } finally {
    port.close();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  process.exit(await main(process.argv.slice(2)));
}
