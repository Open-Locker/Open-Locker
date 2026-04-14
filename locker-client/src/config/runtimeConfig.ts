import { createHash } from "crypto";
import fs from "fs";
import { logger } from "../helper/logger";
import { RUNTIME_CONFIG_OVERLAY_FILE } from "./paths";
import type { CompartmentConfig, LockerConfig } from "./configLoader";

export interface RuntimeConfigOverlay {
  mqtt?: {
    heartbeatInterval?: number;
  };
  compartments?: CompartmentConfig[];
  appliedConfigHash?: string;
  updatedAt?: string;
}

function isPositiveInteger(value: unknown): value is number {
  return Number.isInteger(value) && Number(value) > 0;
}

function isNonNegativeInteger(value: unknown): value is number {
  return Number.isInteger(value) && Number(value) >= 0;
}

export function normalizeCompartments(
  compartments: CompartmentConfig[],
): CompartmentConfig[] {
  return [...compartments]
    .map((compartment) => ({
      id: compartment.id,
      slaveId: compartment.slaveId,
      address: compartment.address,
    }))
    .sort((left, right) => left.id - right.id);
}

export function computeAppliedConfigHash(
  compartments: CompartmentConfig[],
): string {
  const json = JSON.stringify(normalizeCompartments(compartments));

  return createHash("sha256").update(json).digest("hex");
}

function sanitizeCompartments(value: unknown): CompartmentConfig[] {
  if (!Array.isArray(value)) {
    throw new Error("runtime overlay compartments must be an array");
  }

  return normalizeCompartments(
    value.map((entry) => {
      const compartment = entry as Record<string, unknown> | null;

      if (
        compartment === null ||
        typeof compartment !== "object" ||
        !isPositiveInteger(compartment.id) ||
        !isPositiveInteger(compartment.slaveId) ||
        !isNonNegativeInteger(compartment.address)
      ) {
        throw new Error("runtime overlay contains an invalid compartment entry");
      }

      return {
        id: compartment.id,
        slaveId: compartment.slaveId,
        address: compartment.address,
      };
    }),
  );
}

export function sanitizeRuntimeConfigOverlay(
  value: unknown,
): RuntimeConfigOverlay {
  const overlay = value as Record<string, unknown> | null;

  if (overlay === null || typeof overlay !== "object") {
    throw new Error("runtime config overlay must be an object");
  }

  const sanitized: RuntimeConfigOverlay = {};

  if (overlay.mqtt !== undefined) {
    const mqtt = overlay.mqtt as Record<string, unknown> | null;

    if (mqtt === null || typeof mqtt !== "object") {
      throw new Error("runtime overlay mqtt config must be an object");
    }

    if (mqtt.heartbeatInterval !== undefined) {
      if (!isPositiveInteger(mqtt.heartbeatInterval)) {
        throw new Error(
          "runtime overlay mqtt.heartbeatInterval must be a positive integer",
        );
      }

      sanitized.mqtt = {
        heartbeatInterval: mqtt.heartbeatInterval,
      };
    }
  }

  if (overlay.compartments !== undefined) {
    sanitized.compartments = sanitizeCompartments(overlay.compartments);
  }

  if (overlay.appliedConfigHash !== undefined) {
    if (
      typeof overlay.appliedConfigHash !== "string" ||
      !/^[a-f0-9]{64}$/i.test(overlay.appliedConfigHash)
    ) {
      throw new Error(
        "runtime overlay appliedConfigHash must be a 64 character hex string",
      );
    }

    sanitized.appliedConfigHash = overlay.appliedConfigHash;
  }

  if (overlay.updatedAt !== undefined) {
    if (
      typeof overlay.updatedAt !== "string" ||
      Number.isNaN(Date.parse(overlay.updatedAt))
    ) {
      throw new Error("runtime overlay updatedAt must be an ISO date string");
    }

    sanitized.updatedAt = overlay.updatedAt;
  }

  return sanitized;
}

export function loadRuntimeConfigOverlay(): RuntimeConfigOverlay | null {
  try {
    if (!fs.existsSync(RUNTIME_CONFIG_OVERLAY_FILE)) {
      return null;
    }

    const raw = fs.readFileSync(RUNTIME_CONFIG_OVERLAY_FILE, "utf8").trim();
    if (!raw) {
      return null;
    }

    return sanitizeRuntimeConfigOverlay(JSON.parse(raw));
  } catch (error) {
    logger.warn("Failed to load runtime config overlay, ignoring it:", error);
    return null;
  }
}

export function saveRuntimeConfigOverlay(
  overlay: RuntimeConfigOverlay,
): RuntimeConfigOverlay {
  const sanitized = sanitizeRuntimeConfigOverlay(overlay);

  fs.writeFileSync(
    RUNTIME_CONFIG_OVERLAY_FILE,
    JSON.stringify(sanitized, null, 2),
    "utf8",
  );

  logger.info(`Runtime config overlay saved to ${RUNTIME_CONFIG_OVERLAY_FILE}`);

  return sanitized;
}

export function clearRuntimeConfigOverlay(): void {
  if (!fs.existsSync(RUNTIME_CONFIG_OVERLAY_FILE)) {
    return;
  }

  fs.unlinkSync(RUNTIME_CONFIG_OVERLAY_FILE);
  logger.info(`Runtime config overlay cleared at ${RUNTIME_CONFIG_OVERLAY_FILE}`);
}

export function mergeRuntimeConfig(
  baseConfig: LockerConfig,
  overlay: RuntimeConfigOverlay | null,
): LockerConfig {
  if (!overlay) {
    return baseConfig;
  }

  return {
    ...baseConfig,
    mqtt: {
      ...(baseConfig.mqtt ?? {}),
      heartbeatInterval:
        overlay.mqtt?.heartbeatInterval ?? baseConfig.mqtt?.heartbeatInterval,
    },
    compartments: overlay.compartments ?? baseConfig.compartments,
  };
}
