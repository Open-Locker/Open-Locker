import { EventEmitter } from 'node:events';
import { SerialPort } from 'serialport';
import type { SerialConnectionConfig } from '../serial/serial-connection-config';

export interface InjectableSerialPort extends EventEmitter {
  isOpen: boolean;
  open(callback: (error?: Error | null) => void): void;
  close(callback: (error?: Error | null) => void): void;
  write(data: Buffer, callback: (error?: Error | null) => void): void;
  drain(callback: (error?: Error | null) => void): void;
  flush(callback: (error?: Error | null) => void): void;
}

export type SerialPortFactory = (connection: SerialConnectionConfig) => InjectableSerialPort;

export function defaultSerialPortFactory(connection: SerialConnectionConfig): InjectableSerialPort {
  return new SerialPort({
    path: connection.port,
    baudRate: connection.baudRate,
    dataBits: connection.dataBits,
    stopBits: connection.stopBits,
    parity: connection.parity,
    autoOpen: false,
  }) as unknown as InjectableSerialPort;
}
