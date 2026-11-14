import type { FeedbackType } from '../../domain/config';
import { doorStateFromFeedbackSignal } from '../../domain/door-feedback-mapping';

const UNLOCK_HEADER = 0x8a;
const QUERY_HEADER = 0x80;
/** Active feedback: the board reports a door change on its own (ADR-0071). */
const EVENT_HEADER = 0x82;
const UNLOCK_COMMAND = 0x11;
const QUERY_COMMAND = 0x33;
/** Unlock replies and door events: header, board, channel, status, BCC. */
const SHORT_MESSAGE_LENGTH = 5;
/** Header, board, at least one status byte, command marker, BCC. */
const MIN_QUERY_RESPONSE_LENGTH = 5;

/** Verified 8-channel board query-all response at board address 1. */
export const VERIFIED_8_CHANNEL_QUERY_ALL_RESPONSE = Uint8Array.from([
  0x80, 0x01, 0x00, 0x00, 0x00, 0x33, 0xb2,
]);

export function xorBcc(bytes: readonly number[]): number {
  return bytes.reduce((bcc, byte) => bcc ^ byte, 0);
}

export function encodeUnlockRequest(boardAddress: number, zeroBasedChannel: number): Buffer {
  requireBoardAddress(boardAddress);
  if (!Number.isInteger(zeroBasedChannel) || zeroBasedChannel < 0 || zeroBasedChannel > 254) {
    throw new Error('channel must be between 0 and 254');
  }
  return frame([UNLOCK_HEADER, boardAddress, zeroBasedChannel + 1, UNLOCK_COMMAND]);
}

/**
 * Validates the unlock response and returns the door state it reports, mapped
 * through the configured feedback polarity (ADR-0089).
 */
export function decodeUnlockResponse(
  response: Uint8Array,
  boardAddress: number,
  zeroBasedChannel: number,
  feedbackType: FeedbackType,
): 'open' | 'closed' {
  for (const message of splitMessages(response)) {
    const status = message[3];
    if (
      message[0] === UNLOCK_HEADER &&
      message[1] === boardAddress &&
      message[2] === zeroBasedChannel + 1 &&
      (status === 0x00 || status === 0x11)
    ) {
      return doorStateFromFeedbackSignal(feedbackType, status === 0x11);
    }
  }
  throw new Error(
    `no unlock response for board ${boardAddress} channel ${zeroBasedChannel + 1} in ${response.length} bytes`,
  );
}

export function encodeQueryAllRequest(boardAddress: number): Buffer {
  requireBoardAddress(boardAddress);
  return frame([QUERY_HEADER, boardAddress, 0x00, QUERY_COMMAND]);
}

export function decodeQueryAllResponse(
  response: Uint8Array,
  boardAddress: number,
  feedbackType: FeedbackType,
): Array<'open' | 'closed'> {
  const message = findQueryAllResponse(response, boardAddress);

  const commandIndex = message.length - 2;
  const statusByteCount = commandIndex - 2;
  const states: Array<'open' | 'closed'> = [];

  for (let groupFromEnd = statusByteCount - 1; groupFromEnd >= 0; groupFromEnd--) {
    const status = message[2 + (statusByteCount - 1 - groupFromEnd)]!;
    const groupStart = groupFromEnd * 8;
    for (let bit = 0; bit < 8; bit++) {
      states[groupStart + bit] = doorStateFromFeedbackSignal(
        feedbackType,
        (status & (1 << bit)) !== 0,
      );
    }
  }

  return states;
}

function frame(bytes: number[]): Buffer {
  return Buffer.from([...bytes, xorBcc(bytes)]);
}

/** The query-all response for `boardAddress` among the messages of a received burst. */
export function findQueryAllResponse(response: Uint8Array, boardAddress: number): Uint8Array {
  const message = splitMessages(response).find(
    (candidate) => candidate[0] === QUERY_HEADER && candidate[1] === boardAddress,
  );
  if (message === undefined) {
    throw new Error(`no query-all response for board ${boardAddress} in ${response.length} bytes`);
  }
  return message;
}

/**
 * Splits a received burst into messages by their known formats: unlock replies
 * (8A) and door events (82) are five bytes, a query-all response (80) runs to
 * the 33 command marker followed by a matching BCC. The board can send a door
 * event right behind a reply with no gap, so one burst may hold several
 * messages. Bytes that start no valid message are skipped (ADR-0071).
 */
function splitMessages(bytes: Uint8Array): Uint8Array[] {
  const messages: Uint8Array[] = [];
  let position = 0;
  while (position < bytes.length) {
    const message = messageAt(bytes, position);
    if (message === null) {
      position++;
      continue;
    }
    messages.push(message);
    position += message.length;
  }
  return messages;
}

function messageAt(bytes: Uint8Array, start: number): Uint8Array | null {
  const header = bytes[start];
  if (header === UNLOCK_HEADER || header === EVENT_HEADER) {
    const candidate = bytes.subarray(start, start + SHORT_MESSAGE_LENGTH);
    return candidate.length === SHORT_MESSAGE_LENGTH && hasValidBcc(candidate) ? candidate : null;
  }
  if (header === QUERY_HEADER) {
    for (let end = start + MIN_QUERY_RESPONSE_LENGTH; end <= bytes.length; end++) {
      const candidate = bytes.subarray(start, end);
      if (candidate.at(-2) === QUERY_COMMAND && hasValidBcc(candidate)) {
        return candidate;
      }
    }
  }
  return null;
}

function hasValidBcc(message: Uint8Array): boolean {
  return message.at(-1) === xorBcc(Array.from(message.subarray(0, -1)));
}

function requireBoardAddress(boardAddress: number): void {
  if (!Number.isInteger(boardAddress) || boardAddress < 1 || boardAddress > 31) {
    throw new Error('board address must be between 1 and 31');
  }
}
