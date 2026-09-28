export interface SerialConnectionConfig {
  port: string;
  baudRate: number;
  dataBits: 7 | 8;
  stopBits: 1 | 2;
  parity: 'none' | 'even' | 'odd';
}

/** The lock board's firmware fixes 9600 baud, 8N1 (ADR-0067). */
export function lockBoardSerialConnection(port: string): SerialConnectionConfig {
  return {
    port,
    baudRate: 9600,
    dataBits: 8,
    stopBits: 1,
    parity: 'none',
  };
}
