import fs from 'fs';
import type { CompartmentConfig } from '../../domain/compartment';
import type {
  AdapterType,
  ChannelCount,
  FeedbackType,
  RuntimeConfigOverlay,
} from '../../domain/config';
import { SUPPORTED_CHANNEL_COUNTS } from '../../domain/config';
import { normalizeCompartments } from '../../domain/config-normalization';
import { RUNTIME_CONFIG_OVERLAY_FILE } from '../../infrastructure/paths';
import {
  atomicWriteFileSync,
  PersistentStateCorruptedError,
  readPrivateFileSync,
} from '../../infrastructure/file-persistence';

export function sanitizeRuntimeConfigOverlay(value: unknown): RuntimeConfigOverlay {
  const overlay = value as Record<string, unknown> | null;
  if (overlay === null || typeof overlay !== 'object' || Array.isArray(overlay)) {
    throw new Error('runtime config overlay must be an object');
  }
  const allowedKeys = new Set([
    'mqtt',
    'hardwareProfile',
    'compartments',
    'appliedConfigHash',
    'updatedAt',
  ]);
  const keys = Object.keys(overlay);
  if (keys.length === 0 || keys.some((key) => !allowedKeys.has(key))) {
    throw new Error('runtime config overlay contains unsupported fields');
  }

  const sanitized: RuntimeConfigOverlay = {};

  if (overlay.mqtt !== undefined) {
    const mqtt = overlay.mqtt as Record<string, unknown> | null;
    if (
      mqtt === null ||
      typeof mqtt !== 'object' ||
      Array.isArray(mqtt) ||
      Object.keys(mqtt).some((key) => key !== 'heartbeatInterval') ||
      !Number.isInteger(mqtt.heartbeatInterval) ||
      Number(mqtt.heartbeatInterval) <= 0
    ) {
      throw new Error('invalid mqtt settings in overlay');
    }
    sanitized.mqtt = { heartbeatInterval: Number(mqtt.heartbeatInterval) };
  }

  if (overlay.compartments !== undefined) {
    if (!Array.isArray(overlay.compartments)) {
      throw new Error('compartments in overlay must be an array');
    }
    sanitized.compartments = normalizeCompartments(
      (overlay.compartments as CompartmentConfig[]).map((entry) => {
        if (
          !Number.isInteger(entry.compartment_number) ||
          entry.compartment_number <= 0 ||
          !Number.isInteger(entry.slaveId) ||
          entry.slaveId <= 0 ||
          !Number.isInteger(entry.address) ||
          entry.address < 0
        ) {
          throw new Error('invalid compartment entry in overlay');
        }
        return entry;
      }),
    );
  }

  if (overlay.hardwareProfile !== undefined) {
    const profile = overlay.hardwareProfile as Record<string, unknown> | null;
    if (
      profile === null ||
      typeof profile !== 'object' ||
      Array.isArray(profile) ||
      Object.keys(profile).some(
        (key) => !['adapterType', 'channelCount', 'feedbackType'].includes(key),
      ) ||
      !['waveshare_modbus', 'rs485_lock_board'].includes(String(profile.adapterType)) ||
      !SUPPORTED_CHANNEL_COUNTS.includes(
        Number(profile.channelCount) as (typeof SUPPORTED_CHANNEL_COUNTS)[number],
      ) ||
      !['door_closing', 'door_opening'].includes(String(profile.feedbackType))
    ) {
      throw new Error('invalid hardware profile in overlay');
    }
    sanitized.hardwareProfile = {
      adapterType: profile.adapterType as AdapterType,
      channelCount: Number(profile.channelCount) as ChannelCount,
      feedbackType: profile.feedbackType as FeedbackType,
    };
    if (
      sanitized.hardwareProfile.adapterType === 'waveshare_modbus' &&
      sanitized.hardwareProfile.channelCount !== 8
    ) {
      throw new Error('the supported Waveshare hardware profile must have 8 channels');
    }
  }

  if (
    sanitized.compartments &&
    sanitized.hardwareProfile &&
    sanitized.compartments.some((entry) => entry.address >= sanitized.hardwareProfile!.channelCount)
  ) {
    throw new Error('compartment address exceeds hardware channel count');
  }
  if (
    sanitized.hardwareProfile?.adapterType === 'rs485_lock_board' &&
    sanitized.compartments?.some((entry) => entry.slaveId > 31)
  ) {
    throw new Error('RS485 lock board address exceeds DIP range');
  }

  if (overlay.appliedConfigHash !== undefined) {
    if (
      typeof overlay.appliedConfigHash !== 'string' ||
      !/^[a-f0-9]{64}$/i.test(overlay.appliedConfigHash)
    ) {
      throw new Error('invalid appliedConfigHash');
    }
    sanitized.appliedConfigHash = overlay.appliedConfigHash;
  }

  if (overlay.updatedAt !== undefined) {
    if (typeof overlay.updatedAt !== 'string' || !Number.isFinite(Date.parse(overlay.updatedAt))) {
      throw new Error('invalid updatedAt');
    }
    sanitized.updatedAt = overlay.updatedAt;
  }

  return sanitized;
}

export class FileRuntimeOverlayStore {
  constructor(private readonly filePath: string = RUNTIME_CONFIG_OVERLAY_FILE) {}

  load(): RuntimeConfigOverlay | null {
    if (!fs.existsSync(this.filePath)) {
      return null;
    }
    try {
      const raw = readPrivateFileSync(this.filePath).toString('utf8').trim();
      if (!raw) {
        throw new PersistentStateCorruptedError('runtime configuration overlay', this.filePath);
      }
      return sanitizeRuntimeConfigOverlay(JSON.parse(raw));
    } catch (error) {
      if (error instanceof PersistentStateCorruptedError) {
        throw error;
      }
      throw new PersistentStateCorruptedError('runtime configuration overlay', this.filePath, {
        cause: error,
      });
    }
  }

  save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay {
    const sanitized = sanitizeRuntimeConfigOverlay(overlay);
    atomicWriteFileSync(this.filePath, JSON.stringify(sanitized, null, 2), { mode: 0o600 });
    return sanitized;
  }

  clear(): void {
    if (fs.existsSync(this.filePath)) {
      fs.unlinkSync(this.filePath);
    }
  }
}
