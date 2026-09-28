import { performance } from 'node:perf_hooks';
import { calculateInterTransactionDelayMs } from '../serial/serial-framing-timing';
import type { SerialConnectionConfig } from '../serial/serial-connection-config';
import { HardwareTransportError, isReconnectableHardwareError } from '../../domain/errors';
import {
  defaultSerialPortFactory,
  type InjectableSerialPort,
  type SerialPortFactory,
} from './injectable-serial-port';

/**
 * Silence after the last byte that completes a response. USB-RS485 adapters
 * deliver one response in bursts that can be more than the 5 ms Modbus gap
 * apart, so a shorter quiet time cuts frames in half (ADR-0067).
 */
const FRAME_QUIET_MS = 25;

/** The request never reached the wire: nothing the board could have acted on. */
export class RequestNotSentError extends HardwareTransportError {
  constructor(message: string, reconnectable = true) {
    super(message, reconnectable);
    this.name = 'RequestNotSentError';
  }
}

export class ResponseTimeoutError extends HardwareTransportError {
  constructor(
    timeoutMs: number,
    public readonly receivedBytes: number,
  ) {
    super(`RS485 response timed out after ${timeoutMs}ms (${receivedBytes} bytes received)`, true);
    this.name = 'ResponseTimeoutError';
  }
}

/** Throws when a complete frame is not the answer to the pending request. */
export type ResponseValidator = (response: Buffer) => void;

export interface Rs485TransactionTransport {
  open(): Promise<void>;
  close(): Promise<void>;
  isOpen(): boolean;
  transact(request: Uint8Array, timeoutMs: number, validate?: ResponseValidator): Promise<Buffer>;
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
  private readonly timing: TimingDependencies;
  private readonly onPortError = (error: Error): void => {
    this.deferredError = new HardwareTransportError(`RS485 serial error: ${error.message}`, true);
  };

  constructor(
    private readonly connection: SerialConnectionConfig,
    timing: Partial<TimingDependencies> = {},
    private readonly createPort: SerialPortFactory = defaultSerialPortFactory,
    private readonly frameQuietMs = FRAME_QUIET_MS,
  ) {
    this.interTransactionDelayMs = calculateInterTransactionDelayMs(connection);
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

  async transact(
    request: Uint8Array,
    timeoutMs: number,
    validate?: ResponseValidator,
  ): Promise<Buffer> {
    await this.waitForInterTransactionDelay();
    try {
      return await this.transactInternal(request, timeoutMs, validate);
    } finally {
      this.lastTransactionCompletedAt = this.timing.now();
    }
  }

  private async transactInternal(
    request: Uint8Array,
    timeoutMs: number,
    validate: ResponseValidator | undefined,
  ): Promise<Buffer> {
    const port = this.port;
    if (!port?.isOpen) {
      throw new RequestNotSentError('RS485 port is not open');
    }
    if (this.deferredError) {
      const error = this.deferredError;
      this.deferredError = null;
      throw new RequestNotSentError(error.message, error.reconnectable);
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
          await this.discardUntilQuiet(port, timeoutMs);
        } finally {
          reject(error);
        }
      };

      const finish = (): void => {
        if (settled) {
          return;
        }
        if (validate !== undefined) {
          try {
            validate(received);
          } catch (error) {
            void fail(
              new HardwareTransportError(
                error instanceof Error ? error.message : String(error),
                true,
              ),
            );
            return;
          }
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
        }, this.frameQuietMs);
      };

      const onData = (chunk: Buffer): void => {
        if (settled) {
          return;
        }
        received = Buffer.concat([received, chunk]);
        scheduleQuietCompletion();
      };

      responseTimer = setTimeout(
        () => void fail(new ResponseTimeoutError(timeoutMs, received.length)),
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

  /**
   * After a failed transaction the board may still be answering. Waiting for the
   * line to go quiet before discarding keeps a late response from being read as
   * the answer to the next request.
   */
  private async discardUntilQuiet(port: InjectableSerialPort, maxWaitMs: number): Promise<void> {
    if (!port.isOpen) {
      return;
    }

    await new Promise<void>((resolve) => {
      const deadline = setTimeout(done, maxWaitMs);
      let quietTimer = setTimeout(done, this.frameQuietMs);
      const onData = (): void => {
        clearTimeout(quietTimer);
        quietTimer = setTimeout(done, this.frameQuietMs);
      };

      function done(): void {
        clearTimeout(deadline);
        clearTimeout(quietTimer);
        port.off('data', onData);
        resolve();
      }

      port.on('data', onData);
    });

    await this.discardReceiveBuffer(port);
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
