import type { ModbusConfig } from '../../domain/config';

export interface SerialConnectionConfig {
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: 'none' | 'even' | 'odd';
  timeout: number;
}

export function serialConnectionFromModbus(modbus: ModbusConfig): SerialConnectionConfig {
  return {
    port: modbus.port,
    baudRate: modbus.baudRate ?? 9600,
    dataBits: modbus.dataBits ?? 8,
    stopBits: modbus.stopBits ?? 1,
    parity: modbus.parity ?? 'none',
    timeout: modbus.timeout ?? 1000,
  };
}
