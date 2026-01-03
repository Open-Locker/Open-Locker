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
export interface MQTTCommand {
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
export interface MQTTCommandResponse {
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
  cmd: any
): cmd is OpenCompartmentCommand {
  return (
    cmd &&
    cmd.action === "open_compartment" &&
    typeof cmd.transaction_id === "string" &&
    typeof cmd.timestamp === "string" &&
    cmd.data &&
    typeof cmd.data.compartment_number === "number"
  );
}

export function isMQTTCommand(cmd: any): cmd is MQTTCommand {
  return (
    cmd &&
    typeof cmd.action === "string" &&
    typeof cmd.transaction_id === "string" &&
    typeof cmd.timestamp === "string"
  );
}
