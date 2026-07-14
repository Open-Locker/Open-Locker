import fs from 'fs';
import type { CompartmentConfig } from '../../domain/compartment';
import type { RuntimeConfigOverlay } from '../../domain/config';
import { normalizeCompartments } from '../../domain/config-normalization';
import { RUNTIME_CONFIG_OVERLAY_FILE } from '../../infrastructure/paths';

const MAX_RELAY_ADDRESS = 7;

export function sanitizeRuntimeConfigOverlay(value: unknown): RuntimeConfigOverlay {
  const overlay = value as Record<string, unknown> | null;
  if (overlay === null || typeof overlay !== 'object') {
    throw new Error('runtime config overlay must be an object');
  }

  const sanitized: RuntimeConfigOverlay = {};

  if (overlay.mqtt !== undefined) {
    const mqtt = overlay.mqtt as Record<string, unknown>;
    if (
      mqtt.heartbeatInterval !== undefined &&
      Number.isInteger(mqtt.heartbeatInterval) &&
      Number(mqtt.heartbeatInterval) > 0
    ) {
      sanitized.mqtt = { heartbeatInterval: Number(mqtt.heartbeatInterval) };
    }
  }

  if (overlay.compartments !== undefined) {
    sanitized.compartments = normalizeCompartments(
      (overlay.compartments as CompartmentConfig[]).map((entry) => {
        if (
          !Number.isInteger(entry.compartment_number) ||
          entry.compartment_number <= 0 ||
          !Number.isInteger(entry.slaveId) ||
          entry.slaveId <= 0 ||
          !Number.isInteger(entry.address) ||
          entry.address < 0 ||
          entry.address > MAX_RELAY_ADDRESS
        ) {
          throw new Error('invalid compartment entry in overlay');
        }
        return entry;
      }),
    );
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
    sanitized.updatedAt = String(overlay.updatedAt);
  }

  return sanitized;
}

export class FileRuntimeOverlayStore {
  load(): RuntimeConfigOverlay | null {
    if (!fs.existsSync(RUNTIME_CONFIG_OVERLAY_FILE)) {
      return null;
    }
    const raw = fs.readFileSync(RUNTIME_CONFIG_OVERLAY_FILE, 'utf8').trim();
    if (!raw) {
      return null;
    }
    return sanitizeRuntimeConfigOverlay(JSON.parse(raw));
  }

  save(overlay: RuntimeConfigOverlay): RuntimeConfigOverlay {
    const sanitized = sanitizeRuntimeConfigOverlay(overlay);
    fs.writeFileSync(RUNTIME_CONFIG_OVERLAY_FILE, JSON.stringify(sanitized, null, 2), 'utf8');
    return sanitized;
  }

  clear(): void {
    if (fs.existsSync(RUNTIME_CONFIG_OVERLAY_FILE)) {
      fs.unlinkSync(RUNTIME_CONFIG_OVERLAY_FILE);
    }
  }
}
