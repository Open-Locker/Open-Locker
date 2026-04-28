import { MQTTMessageEnvelope } from "../helper/mqttMessage";
import { z } from "zod";

/**
 * MQTT Message Type Definitions
 * Based on the Open Locker MQTT Protocol Specification
 */

// ============================================================================
// Commands (Backend -> Client)
// ============================================================================

const nonEmptyStringSchema = z.string().trim().min(1);
const positiveIntegerSchema = z.number().int().positive();
const nonNegativeIntegerSchema = z.number().int().nonnegative();
const sha256HexSchema = z.string().regex(/^[a-f0-9]{64}$/i);

export const mqttCommandEnvelopeSchema = z.object({
  action: nonEmptyStringSchema,
  message_id: nonEmptyStringSchema,
  transaction_id: nonEmptyStringSchema,
  timestamp: nonEmptyStringSchema,
});

export const mqttCommandSchema = mqttCommandEnvelopeSchema.extend({
  data: z.record(z.string(), z.unknown()).optional(),
});

/**
 * Base structure for all commands received from the backend
 */
export type MQTTCommand = z.infer<typeof mqttCommandSchema>;

/**
 * Command to open a specific compartment
 */
export const openCompartmentCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal("open_compartment"),
  data: z.object({
    compartment_number: positiveIntegerSchema,
  }),
});

export type OpenCompartmentCommand = z.infer<typeof openCompartmentCommandSchema>;

const applyConfigCompartmentSchema = z.object({
  compartment_number: positiveIntegerSchema,
  slaveId: positiveIntegerSchema,
  address: nonNegativeIntegerSchema,
});

export const applyConfigCommandSchema = mqttCommandEnvelopeSchema.extend({
  action: z.literal("apply_config"),
  data: z.object({
    config_hash: sha256HexSchema,
    heartbeat_interval_seconds: positiveIntegerSchema,
    compartments: z.array(applyConfigCompartmentSchema),
  }),
});

export type ApplyConfigCommand = z.infer<typeof applyConfigCommandSchema>;

export const knownMQTTCommandSchema = z.discriminatedUnion("action", [
  openCompartmentCommandSchema,
  applyConfigCommandSchema,
]);

export type KnownMQTTCommand = z.infer<typeof knownMQTTCommandSchema>;

// ============================================================================
// Responses (Client -> Backend)
// ============================================================================

/**
 * Base structure for command responses sent by the client
 */
export interface MQTTCommandResponse extends MQTTMessageEnvelope {
  type: "command_response";
  action: string;
  result: "success" | "error";
  transaction_id: string;
  timestamp: string; // ISO 8601
  message?: string;
  error_code?: string;
  applied_config_hash?: string;
}

/**
 * Success response for command execution
 */
export interface SuccessResponse extends MQTTCommandResponse {
  result: "success";
  message: string;
}

/**
 * Error response for command execution
 */
export interface ErrorResponse extends MQTTCommandResponse {
  result: "error";
  error_code: string;
  message: string;
}

// ============================================================================
// Error Codes
// ============================================================================

export enum MQTTErrorCode {
  DOOR_JAMMED = "DOOR_JAMMED",
  COMPARTMENT_NOT_FOUND = "COMPARTMENT_NOT_FOUND",
  HARDWARE_ERROR = "HARDWARE_ERROR",
  MODBUS_ERROR = "MODBUS_ERROR",
  INVALID_COMMAND = "INVALID_COMMAND",
  INVALID_CONFIG = "INVALID_CONFIG",
  TIMEOUT = "TIMEOUT",
  UNKNOWN_ERROR = "UNKNOWN_ERROR",
}

// ============================================================================
// Parsing Helpers
// ============================================================================

export function parseMQTTCommand(cmd: unknown): MQTTCommand | null {
  const result = mqttCommandSchema.safeParse(cmd);
  return result.success ? result.data : null;
}

export function parseOpenCompartmentCommand(
  cmd: unknown,
): OpenCompartmentCommand | null {
  const result = openCompartmentCommandSchema.safeParse(cmd);
  return result.success ? result.data : null;
}

export function parseApplyConfigCommand(cmd: unknown): ApplyConfigCommand | null {
  const result = applyConfigCommandSchema.safeParse(cmd);
  return result.success ? result.data : null;
}

export function parseKnownMQTTCommand(cmd: unknown): KnownMQTTCommand | null {
  const result = knownMQTTCommandSchema.safeParse(cmd);
  return result.success ? result.data : null;
}

// ============================================================================
// Type Guards
// ============================================================================

export function isOpenCompartmentCommand(
  cmd: unknown,
): cmd is OpenCompartmentCommand {
  return parseOpenCompartmentCommand(cmd) !== null;
}

export function isApplyConfigCommand(cmd: unknown): cmd is ApplyConfigCommand {
  return parseApplyConfigCommand(cmd) !== null;
}

export function isMQTTCommand(cmd: unknown): cmd is MQTTCommand {
  return parseMQTTCommand(cmd) !== null;
}
