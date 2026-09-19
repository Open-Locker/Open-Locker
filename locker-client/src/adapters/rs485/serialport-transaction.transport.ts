import { performance } from 'node:perf_hooks';
import {
  calculateInterByteFrameQuietMs,
  calculateInterTransactionDelayMs,
} from '../serial/serial-framing-timing';
import type { SerialConnectionConfig } from '../serial/serial-connection-config';
import { HardwareTransportError, isReconnectableHardwareError } from '../../domain/errors';
import {
  defaultSerialPortFactory,
  type InjectableSerialPort,
  type SerialPortFactory,
} from './injectable-serial-port';

export interface Rs485TransactionTransport {
  open(): Promise<void>;
  close(): Promise<void>;
  isOpen(): boolean;
  transact(request: Uint8Array, timeoutMs: number): Promise<Buffer>;
}

interface TimingDependencies {
  now(): number;
  sleep(delayMs: number): Promise<void>;
}

export class SerialPortTransactionTransport implements Rs485TransactionTransport {
  private port: InjectableSerialPort | null = null;
  private deferredError: HardwareTransportError | null = null;
  private lastTransactionCompletedAt: number | null = null;
  private readonly interTransactionDelayMs: number;
  private readonly interByteQuietMs: number;
  private readonly timing: TimingDependencies;
  private readonly onPortError = (error: Error): void => {
    this.deferredError = new HardwareTransportError(`RS485 serial error: ${error.message}`, true);
  };

  constructor(
    private readonly connection: SerialConnectionConfig,
    timing: Partial<TimingDependencies> = {},
    private readonly createPort: SerialPortFactory = defaultSerialPortFactory,
  ) {
    const framing = {
      baudRate: connection.baudRate,
      dataBits: connection.dataBits,
      stopBits: connection.stopBits,
      parity: connection.parity,
    };
    this.interTransactionDelayMs = calculateInterTransactionDelayMs(framing);
    this.interByteQuietMs = calculateInterByteFrameQuietMs(framing);
    this.timing = {
      now: timing.now ?? (() => performance.now()),
      sleep: timing.sleep ?? ((delayMs) => new Promise((resolve) => setTimeout(resolve, delayMs))),
    };
  }

  async open(): Promise<void> {
    if (this.port?.isOpen) {
      return;
    }
    await this.close();
    const port = this.createPort(this.connection);
    this.deferredError = null;
    port.on('error', this.onPortError);
    try {
      await callbackPromise((done) => port.open(done));
      this.port = port;
    } catch (error) {
      port.off('error', this.onPortError);
      throw new HardwareTransportError(
        `Could not open RS485 serial port: ${error instanceof Error ? error.message : String(error)}`,
        isReconnectableHardwareError(error),
      );
    }
  }

  async close(): Promise<void> {
    const port = this.port;
    this.port = null;
    this.deferredError = null;
    this.lastTransactionCompletedAt = null;
    if (!port) {
      return;
    }
    try {
      if (port.isOpen) {
        await callbackPromise((done) => port.close(done));
      }
    } finally {
      port.off('error', this.onPortError);
    }
  }

  isOpen(): boolean {
    return Boolean(this.port?.isOpen);
  }

  async transact(request: Uint8Array, timeoutMs: number): Promise<Buffer> {
    await this.waitForInterTransactionDelay();
    try {
      return await this.transactInternal(request, timeoutMs);
    } finally {
      this.lastTransactionCompletedAt = this.timing.now();
    }
  }

  private async transactInternal(request: Uint8Array, timeoutMs: number): Promise<Buffer> {
    const port = this.port;
    if (!port?.isOpen) {
      throw new HardwareTransportError('RS485 port is not open', true);
    }
    if (this.deferredError) {
      const error = this.deferredError;
      this.deferredError = null;
      throw error;
    }

    await this.discardReceiveBuffer(port);

    return new Promise<Buffer>((resolve, reject) => {
      let received = Buffer.alloc(0);
      let settled = false;
      let quietTimer: ReturnType<typeof setTimeout> | undefined;
      let responseTimer: ReturnType<typeof setTimeout> | undefined;

      const cleanup = (): void => {
        if (quietTimer !== undefined) {
          clearTimeout(quietTimer);
          quietTimer = undefined;
        }
        if (responseTimer !== undefined) {
          clearTimeout(responseTimer);
          responseTimer = undefined;
        }
        port.off('data', onData);
        port.off('error', onError);
      };

      const fail = async (error: Error): Promise<void> => {
        if (settled) {
          return;
        }
        settled = true;
        cleanup();
        try {
          await this.discardReceiveBuffer(port);
        } finally {
          reject(error);
        }
      };

      const finish = (): void => {
        if (settled) {
          return;
        }
        settled = true;
        cleanup();
        resolve(received);
      };

      const onError = (error: Error): void => {
        void fail(new HardwareTransportError(`RS485 serial error: ${error.message}`, true));
      };

      const scheduleQuietCompletion = (): void => {
        if (quietTimer !== undefined) {
          clearTimeout(quietTimer);
        }
        quietTimer = setTimeout(() => {
          if (received.length === 0) {
            return;
          }
          finish();
        }, this.interByteQuietMs);
      };

      const onData = (chunk: Buffer): void => {
        if (settled) {
          return;
        }
        received = Buffer.concat([received, chunk]);
        scheduleQuietCompletion();
      };

      responseTimer = setTimeout(
        () =>
          void fail(
            new HardwareTransportError(
              `RS485 response timed out after ${timeoutMs}ms (${received.length} bytes received)`,
              true,
            ),
          ),
        timeoutMs,
      );

      port.on('data', onData);
      port.once('error', onError);
      port.write(Buffer.from(request), (writeError) => {
        if (writeError) {
          onError(writeError);
          return;
        }
        port.drain((drainError) => {
          if (drainError) {
            onError(drainError);
          }
        });
      });
    });
  }

  private async waitForInterTransactionDelay(): Promise<void> {
    if (this.lastTransactionCompletedAt === null) {
      return;
    }

    const elapsedMs = this.timing.now() - this.lastTransactionCompletedAt;
    const remainingMs = this.interTransactionDelayMs - elapsedMs;
    if (remainingMs > 0) {
      await this.timing.sleep(remainingMs);
    }
  }

  private async discardReceiveBuffer(port: InjectableSerialPort): Promise<void> {
    if (!port.isOpen) {
      return;
    }

    await new Promise<void>((resolve) => {
      port.flush(() => resolve());
    });
  }
}

function callbackPromise(register: (done: (error?: Error | null) => void) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    register((error) => (error ? reject(error) : resolve()));
  });
}
