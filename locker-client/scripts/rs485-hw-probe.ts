import { SerialPort } from 'serialport';
import {
  decodeQueryAllResponse,
  decodeUnlockResponse,
  encodeQueryAllRequest,
  encodeUnlockRequest,
  queryAllResponseLength,
} from '../src/adapters/rs485/rs485-lock-board-codec';
import type { ChannelCount, FeedbackType } from '../src/domain/config';

const PORT = process.env.RS485_PORT ?? '/dev/cu.usbmodem5AF50025321';
const BOARD = Number(process.env.RS485_BOARD ?? '1');
const CHANNEL = Number(process.env.RS485_CHANNEL ?? '0');
const CHANNEL_COUNTS = [8, 12, 18, 24, 36, 50] as const satisfies readonly ChannelCount[];

function hex(bytes: Uint8Array): string {
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join(' ');
}

function transact(port: SerialPort, request: Buffer, timeoutMs: number): Promise<Buffer> {
  return new Promise((resolve, reject) => {
    let received = Buffer.alloc(0);
    const cleanup = (): void => {
      clearTimeout(timer);
      port.off('data', onData);
      port.off('error', onError);
    };
    const onError = (error: Error): void => {
      cleanup();
      reject(error);
    };
    const onData = (chunk: Buffer): void => {
      received = Buffer.concat([received, chunk]);
    };
    const timer = setTimeout(() => {
      cleanup();
      resolve(received);
    }, timeoutMs);
    port.on('data', onData);
    port.once('error', onError);
    port.write(request, (writeError: Error | null | undefined) => {
      if (writeError) {
        onError(writeError);
      }
    });
  });
}

function decodeQuery(response: Buffer): void {
  for (const channelCount of CHANNEL_COUNTS) {
    const expected = queryAllResponseLength(channelCount);
    if (response.length !== expected) {
      continue;
    }
    for (const feedbackType of ['door_closing', 'door_opening'] as const satisfies FeedbackType[]) {
      try {
        const states = decodeQueryAllResponse(response, BOARD, channelCount, feedbackType);
        console.log(`decoded query-all as ${channelCount}/${feedbackType}: ${JSON.stringify(states)}`);
      } catch (error) {
        console.log(
          `decode query-all failed for ${channelCount}/${feedbackType}: ${
            error instanceof Error ? error.message : String(error)
          }`,
        );
      }
    }
  }
}

async function main(): Promise<void> {
  console.log(`opening ${PORT} @ 9600 8N1, board=${BOARD}, unlock channel=${CHANNEL} (0-based)`);
  const port = new SerialPort({
    path: PORT,
    baudRate: 9600,
    dataBits: 8,
    stopBits: 1,
    parity: 'none',
    autoOpen: false,
  });
  await new Promise<void>((resolve, reject) => {
    port.open((error: Error | null) => (error ? reject(error) : resolve()));
  });

  try {
    const query = encodeQueryAllRequest(BOARD);
    console.log(`TX query-all: ${hex(query)}`);
    const queryResponse = await transact(port, query, 800);
    console.log(`RX query-all (${queryResponse.length} bytes): ${hex(queryResponse)}`);
    decodeQuery(queryResponse);

    const unlock = encodeUnlockRequest(BOARD, CHANNEL);
    console.log(`TX unlock: ${hex(unlock)}`);
    const unlockResponse = await transact(port, unlock, 1500);
    console.log(`RX unlock (${unlockResponse.length} bytes): ${hex(unlockResponse)}`);
    if (unlockResponse.length === 5) {
      for (const feedbackType of ['door_closing', 'door_opening'] as const satisfies FeedbackType[]) {
        try {
          const result = decodeUnlockResponse(unlockResponse, BOARD, CHANNEL, feedbackType);
          console.log(`decoded unlock as ${feedbackType}: ${result}`);
        } catch (error) {
          console.log(
            `decode unlock failed for ${feedbackType}: ${
              error instanceof Error ? error.message : String(error)
            }`,
          );
        }
      }
    }

    await new Promise((resolve) => setTimeout(resolve, 400));
    const after = await transact(port, encodeQueryAllRequest(BOARD), 800);
    console.log(`RX query-all after unlock (${after.length} bytes): ${hex(after)}`);
    decodeQuery(after);
  } finally {
    await new Promise<void>((resolve, reject) => {
      port.close((error: Error | null) => (error ? reject(error) : resolve()));
    });
  }
}

main().catch((error: unknown) => {
  console.error(error);
  process.exitCode = 1;
});
