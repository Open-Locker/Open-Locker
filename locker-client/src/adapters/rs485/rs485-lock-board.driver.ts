import type { FeedbackType } from '../../domain/config';
import {
  BoardNotRespondingError,
  HardwareTransportError,
  UnlockNotSentError,
  UnlockOutcomeUnknownError,
} from '../../domain/errors';
import {
  decodeQueryAllResponse,
  decodeUnlockResponse,
  encodeQueryAllRequest,
  encodeUnlockRequest,
  validateQueryAllFrame,
} from './rs485-lock-board-codec';
import {
  RequestNotSentError,
  ResponseTimeoutError,
  type Rs485TransactionTransport,
} from './serialport-transaction.transport';

/** Above the board's roughly 500 ms unlock response delay (ADR-0067). */
const RS485_RESPONSE_TIMEOUT_MS = 1500;

export interface Rs485LockBoardDriverPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
  /** Door state the board reports in its unlock response. */
  unlock(boardAddress: number, channel: number): Promise<'open' | 'closed'>;
  queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>>;
}

export class Rs485LockBoardDriver implements Rs485LockBoardDriverPort {
  constructor(
    private readonly transport: Rs485TransactionTransport,
    private readonly feedbackType: FeedbackType,
    private readonly timeoutMs = RS485_RESPONSE_TIMEOUT_MS,
  ) {}

  connect(): Promise<void> {
    return this.transport.open();
  }

  disconnect(): Promise<void> {
    return this.transport.close();
  }

  isOpen(): boolean {
    return this.transport.isOpen();
  }

  async unlock(boardAddress: number, channel: number): Promise<'open' | 'closed'> {
    requireWireChannel(channel);
    const request = encodeUnlockRequest(boardAddress, channel);
    let response: Buffer;
    try {
      response = await this.transport.transact(request, this.timeoutMs, (frame) =>
        decodeUnlockResponse(frame, boardAddress, channel, this.feedbackType),
      );
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      const reconnectable = error instanceof HardwareTransportError && error.reconnectable;
      if (error instanceof RequestNotSentError) {
        throw new UnlockNotSentError(message, reconnectable);
      }
      throw new UnlockOutcomeUnknownError(message, reconnectable);
    }
    return decodeUnlockResponse(response, boardAddress, channel, this.feedbackType);
  }

  async queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>> {
    let response: Buffer;
    try {
      response = await this.transport.transact(
        encodeQueryAllRequest(boardAddress),
        this.timeoutMs,
        (frame) => validateQueryAllFrame(frame, boardAddress),
      );
    } catch (error) {
      if (error instanceof ResponseTimeoutError && error.receivedBytes === 0) {
        throw new BoardNotRespondingError(`RS485 board ${boardAddress} did not respond`);
      }
      throw error;
    }
    return decodeQueryAllResponse(response, boardAddress, this.feedbackType);
  }
}

function requireWireChannel(channel: number): void {
  if (!Number.isInteger(channel) || channel < 0 || channel > 254) {
    throw new Error('channel must be between 0 and 254');
  }
}
