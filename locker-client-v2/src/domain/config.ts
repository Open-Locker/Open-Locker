import type { CompartmentConfig } from './compartment';

export interface LockerConfig {
  mqtt?: {
    heartbeatInterval?: number;
    cleanSession?: boolean;
    keepaliveSeconds?: number;
    reconnectPeriodMs?: number;
    connectTimeoutMs?: number;
    maxReconnectAttempts?: number;
  };
  modbus: {
    port: string;
    flashDurationMs?: number;
    baudRate?: number;
    dataBits?: 7 | 8;
    stopBits?: 1 | 2;
    parity?: 'none' | 'even' | 'odd';
    timeout?: number;
  };
  compartments?: CompartmentConfig[];
  logging?: {
    level?: string;
  };
}

export interface RuntimeConfigOverlay {
  mqtt?: {
    heartbeatInterval?: number;
  };
  compartments?: CompartmentConfig[];
  appliedConfigHash?: string;
  updatedAt?: string;
}
