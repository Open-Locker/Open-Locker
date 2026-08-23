import type { ChannelCount, FeedbackType } from '../../domain/config';

const UNLOCK_HEADER = 0x8a;
const QUERY_HEADER = 0x80;
const UNLOCK_COMMAND = 0x11;
const QUERY_COMMAND = 0x33;

export function xorBcc(bytes: readonly number[]): number {
  return bytes.reduce((bcc, byte) => bcc ^ byte, 0);
}

export function encodeUnlockRequest(boardAddress: number, zeroBasedChannel: number): Buffer {
  requireBoardAddress(boardAddress);
  if (!Number.isInteger(zeroBasedChannel) || zeroBasedChannel < 0 || zeroBasedChannel > 49) {
    throw new Error('channel must be between 0 and 49');
  }
  return frame([UNLOCK_HEADER, boardAddress, zeroBasedChannel + 1, UNLOCK_COMMAND]);
}

export function decodeUnlockResponse(
  response: Uint8Array,
  boardAddress: number,
  zeroBasedChannel: number,
  feedbackType: FeedbackType,
): 'opened' | 'failed' {
  validateFrame(response, 5);
  if (
    response[0] !== UNLOCK_HEADER ||
    response[1] !== boardAddress ||
    response[2] !== zeroBasedChannel + 1
  ) {
    throw new Error('unlock response header, board, or channel does not match request');
  }
  const status = response[3];
  if (status !== 0x00 && status !== 0x11) {
    throw new Error(`unsupported unlock response status 0x${status?.toString(16)}`);
  }
  const opened = feedbackType === 'door_closing' ? status === 0x00 : status === 0x11;
  return opened ? 'opened' : 'failed';
}

export function encodeQueryAllRequest(boardAddress: number): Buffer {
  requireBoardAddress(boardAddress);
  return frame([QUERY_HEADER, boardAddress, 0x00, QUERY_COMMAND]);
}

export function queryAllResponseLength(channelCount: ChannelCount): number {
  return Math.ceil(channelCount / 8) + 4;
}

export function decodeQueryAllResponse(
  response: Uint8Array,
  boardAddress: number,
  channelCount: ChannelCount,
  feedbackType: FeedbackType,
): Array<'open' | 'closed'> {
  const statusByteCount = Math.ceil(channelCount / 8);
  validateFrame(response, queryAllResponseLength(channelCount));
  if (
    response[0] !== QUERY_HEADER ||
    response[1] !== boardAddress ||
    response[response.length - 2] !== QUERY_COMMAND
  ) {
    throw new Error('query-all response header, board, or command does not match request');
  }

  const states: Array<'open' | 'closed'> = [];
  for (let groupFromEnd = statusByteCount - 1; groupFromEnd >= 0; groupFromEnd--) {
    const status = response[2 + (statusByteCount - 1 - groupFromEnd)]!;
    const groupStart = groupFromEnd * 8;
    const channelsInGroup = Math.min(8, channelCount - groupStart);
    for (let bit = 0; bit < channelsInGroup; bit++) {
      const signalHigh = (status & (1 << bit)) !== 0;
      const closed = feedbackType === 'door_closing' ? signalHigh : !signalHigh;
      states[groupStart + bit] = closed ? 'closed' : 'open';
    }
  }
  return states;
}

function frame(bytes: number[]): Buffer {
  return Buffer.from([...bytes, xorBcc(bytes)]);
}

function validateFrame(response: Uint8Array, expectedLength: number): void {
  if (response.length !== expectedLength) {
    throw new Error(`invalid response length: expected ${expectedLength}, got ${response.length}`);
  }
  const body = Array.from(response.slice(0, -1));
  if (response.at(-1) !== xorBcc(body)) {
    throw new Error('invalid response BCC');
  }
}

function requireBoardAddress(boardAddress: number): void {
  if (!Number.isInteger(boardAddress) || boardAddress < 1 || boardAddress > 31) {
    throw new Error('board address must be between 1 and 31');
  }
}
