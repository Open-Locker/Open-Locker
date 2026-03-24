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

export function isOpenCompartmentCommand(
  cmd: unknown,
): cmd is OpenCompartmentCommand {
  const candidate = cmd as Record<string, any> | null;

  return (
    candidate !== null &&
    typeof candidate === "object" &&
    candidate.action === "open_compartment" &&
    typeof candidate.message_id === "string" &&
    typeof candidate.transaction_id === "string" &&
    typeof candidate.timestamp === "string" &&
    candidate.data !== null &&
    typeof candidate.data === "object" &&
    typeof candidate.data.compartment_number === "number"
  );
}

export function isMQTTCommand(cmd: unknown): cmd is MQTTCommand {
  const candidate = cmd as Record<string, any> | null;

  return (
    candidate !== null &&
    typeof candidate === "object" &&
    typeof candidate.action === "string" &&
    typeof candidate.message_id === "string" &&
    typeof candidate.transaction_id === "string" &&
    typeof candidate.timestamp === "string"
  );
}
