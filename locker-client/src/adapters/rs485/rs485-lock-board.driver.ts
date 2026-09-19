import type { FeedbackType } from '../../domain/config';
import { HardwareTransportError } from '../../domain/errors';
import {
  decodeQueryAllResponse,
  decodeUnlockAck,
  encodeQueryAllRequest,
  encodeUnlockRequest,
} from './rs485-lock-board-codec';
import type { Rs485TransactionTransport } from './serialport-transaction.transport';

export interface Rs485LockBoardDriverPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
  unlock(boardAddress: number, channel: number): Promise<void>;
  queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>>;
}

export class Rs485LockBoardDriver implements Rs485LockBoardDriverPort {
  constructor(
    private readonly transport: Rs485TransactionTransport,
    private readonly feedbackType: FeedbackType,
    private readonly timeoutMs = 1500,
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

  async unlock(boardAddress: number, channel: number): Promise<void> {
    requireWireChannel(channel);
    const response = await this.transport.transact(
      encodeUnlockRequest(boardAddress, channel),
      this.timeoutMs,
    );
    try {
      decodeUnlockAck(response, boardAddress, channel);
    } catch (error) {
      throw new HardwareTransportError(
        error instanceof Error ? error.message : String(error),
        true,
      );
    }
  }

  async queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>> {
    const response = await this.transport.transact(
      encodeQueryAllRequest(boardAddress),
      this.timeoutMs,
    );
    try {
      return decodeQueryAllResponse(response, boardAddress, this.feedbackType);
    } catch (error) {
      throw new HardwareTransportError(
        error instanceof Error ? error.message : String(error),
        true,
      );
    }
  }
}

function requireWireChannel(channel: number): void {
  if (!Number.isInteger(channel) || channel < 0 || channel > 254) {
    throw new Error('channel must be between 0 and 254');
  }
}
