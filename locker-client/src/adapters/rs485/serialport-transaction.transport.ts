import { SerialPort } from 'serialport';
import { HardwareTransportError } from '../../domain/errors';

export interface Rs485TransactionTransport {
  open(): Promise<void>;
  close(): Promise<void>;
  isOpen(): boolean;
  transact(request: Uint8Array, expectedResponseLength: number, timeoutMs: number): Promise<Buffer>;
}

export class SerialPortTransactionTransport implements Rs485TransactionTransport {
  private port: SerialPort | null = null;

  constructor(
    private readonly path: string,
    private readonly baudRate = 9600,
  ) {}

  async open(): Promise<void> {
    if (this.port?.isOpen) {
      return;
    }
    await this.close();
    const port = new SerialPort({
      path: this.path,
      baudRate: this.baudRate,
      dataBits: 8,
      stopBits: 1,
      parity: 'none',
      autoOpen: false,
    });
    await callbackPromise((done) => port.open(done));
    this.port = port;
  }

  async close(): Promise<void> {
    const port = this.port;
    this.port = null;
    if (port?.isOpen) {
      await callbackPromise((done) => port.close(done));
    }
  }

  isOpen(): boolean {
    return Boolean(this.port?.isOpen);
  }

  async transact(
    request: Uint8Array,
    expectedResponseLength: number,
    timeoutMs: number,
  ): Promise<Buffer> {
    const port = this.port;
    if (!port?.isOpen) {
      throw new HardwareTransportError('RS485 port is not open', true);
    }

    return new Promise<Buffer>((resolve, reject) => {
      let received = Buffer.alloc(0);
      const cleanup = (): void => {
        clearTimeout(timer);
        port.off('data', onData);
        port.off('error', onError);
      };
      const fail = (error: Error): void => {
        cleanup();
        reject(error);
      };
      const onError = (error: Error): void =>
        fail(new HardwareTransportError(`RS485 serial error: ${error.message}`, true));
      const onData = (chunk: Buffer): void => {
        received = Buffer.concat([received, chunk]);
        if (received.length >= expectedResponseLength) {
          cleanup();
          if (received.length !== expectedResponseLength) {
            reject(
              new HardwareTransportError(
                `RS485 response overflow: expected ${expectedResponseLength}, got ${received.length}`,
              ),
            );
            return;
          }
          resolve(received);
        }
      };
      const timer = setTimeout(
        () =>
          fail(
            new HardwareTransportError(
              `RS485 response timed out after ${timeoutMs}ms (${received.length}/${expectedResponseLength} bytes)`,
              true,
            ),
          ),
        timeoutMs,
      );

      port.on('data', onData);
      port.once('error', onError);
      port.write(request, (writeError) => {
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
}

function callbackPromise(register: (done: (error?: Error | null) => void) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    register((error) => (error ? reject(error) : resolve()));
  });
}
