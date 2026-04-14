import { MQTTMessageEnvelope } from "../helper/mqttMessage";

/**
 * MQTT Message Type Definitions
 * Based on the Open Locker MQTT Protocol Specification
 */

// ============================================================================
// Commands (Backend -> Client)
// ============================================================================

/**
 * Base structure for all commands received from the backend
 */
export interface MQTTCommand extends MQTTMessageEnvelope {
  action: string;
  transaction_id: string;
  timestamp: string; // ISO 8601
  data?: Record<string, any>;
}

/**
 * Command to open a specific compartment
 */
export interface OpenCompartmentCommand extends MQTTCommand {
  action: "open_compartment";
  data: {
    compartment_number: number;
  };
}

export interface ApplyConfigCommand extends MQTTCommand {
  action: "apply_config";
  data: {
    config_hash: string;
    heartbeat_interval_seconds: number;
    compartments: Array<{
      id: number;
      slaveId: number;
      address: number;
    }>;
  };
}

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
  TIMEOUT = "TIMEOUT",
  UNKNOWN_ERROR = "UNKNOWN_ERROR",
}

// ============================================================================
// Type Guards
// ============================================================================

function isNonEmptyString(value: unknown): value is string {
  return typeof value === "string" && value.trim().length > 0;
}

export function isOpenCompartmentCommand(
  cmd: unknown,
): cmd is OpenCompartmentCommand {
  const candidate = cmd as Record<string, any> | null;

  return (
    candidate !== null &&
    typeof candidate === "object" &&
    candidate.action === "open_compartment" &&
    isNonEmptyString(candidate.message_id) &&
    isNonEmptyString(candidate.transaction_id) &&
    isNonEmptyString(candidate.timestamp) &&
    candidate.data !== null &&
    typeof candidate.data === "object" &&
    typeof candidate.data.compartment_number === "number"
  );
}

export function isApplyConfigCommand(cmd: unknown): cmd is ApplyConfigCommand {
  const candidate = cmd as Record<string, any> | null;
  const data = candidate?.data as Record<string, unknown> | null | undefined;

  return (
    candidate !== null &&
    typeof candidate === "object" &&
    candidate.action === "apply_config" &&
    isNonEmptyString(candidate.message_id) &&
    isNonEmptyString(candidate.transaction_id) &&
    isNonEmptyString(candidate.timestamp) &&
    data !== null &&
    typeof data === "object" &&
    isNonEmptyString(data.config_hash) &&
    typeof data.heartbeat_interval_seconds === "number" &&
    Array.isArray(data.compartments) &&
    data.compartments.every((compartment) => {
      const compartmentRecord = compartment as Record<string, unknown> | null;

      return (
        compartmentRecord !== null &&
        typeof compartmentRecord === "object" &&
        typeof compartmentRecord.id === "number" &&
        typeof compartmentRecord.slaveId === "number" &&
        typeof compartmentRecord.address === "number"
      );
    })
  );
}

export function isMQTTCommand(cmd: unknown): cmd is MQTTCommand {
  const candidate = cmd as Record<string, any> | null;

  return (
    candidate !== null &&
    typeof candidate === "object" &&
    isNonEmptyString(candidate.action) &&
    isNonEmptyString(candidate.message_id) &&
    isNonEmptyString(candidate.transaction_id) &&
    isNonEmptyString(candidate.timestamp)
  );
}
