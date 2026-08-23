import type { ChannelCount, FeedbackType } from '../../domain/config';
import {
  decodeQueryAllResponse,
  decodeUnlockResponse,
  encodeQueryAllRequest,
  encodeUnlockRequest,
  queryAllResponseLength,
} from './rs485-lock-board-codec';
import type { Rs485TransactionTransport } from './serialport-transaction.transport';

export interface Rs485LockBoardDriverPort {
  connect(): Promise<void>;
  disconnect(): Promise<void>;
  isOpen(): boolean;
  unlock(boardAddress: number, channel: number): Promise<'opened' | 'failed'>;
  queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>>;
}

export class Rs485LockBoardDriver implements Rs485LockBoardDriverPort {
  constructor(
    private readonly transport: Rs485TransactionTransport,
    private readonly channelCount: ChannelCount,
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

  async unlock(boardAddress: number, channel: number): Promise<'opened' | 'failed'> {
    this.requireChannel(channel);
    const response = await this.transport.transact(
      encodeUnlockRequest(boardAddress, channel),
      5,
      this.timeoutMs,
    );
    return decodeUnlockResponse(response, boardAddress, channel, this.feedbackType);
  }

  async queryAll(boardAddress: number): Promise<Array<'open' | 'closed'>> {
    const response = await this.transport.transact(
      encodeQueryAllRequest(boardAddress),
      queryAllResponseLength(this.channelCount),
      this.timeoutMs,
    );
    return decodeQueryAllResponse(response, boardAddress, this.channelCount, this.feedbackType);
  }

  private requireChannel(channel: number): void {
    if (!Number.isInteger(channel) || channel < 0 || channel >= this.channelCount) {
      throw new Error(`channel must be between 0 and ${this.channelCount - 1}`);
    }
  }
}
