import type { OpenDetectionOutcome } from '../domain/door-detection';

/** Reported after watching the door following an unlock. */
export interface OpenDetectionEvent {
  compartmentNumber: number;
  transactionId: string;
  outcome: OpenDetectionOutcome;
  /** Time from the unlock to the door being observed open; null when not applicable. */
  detectionMs: number | null;
}

/** Reported when a door opens with no actuation that could explain it. */
export interface UncommandedOpenEvent {
  compartmentNumber: number;
  /** Age of the last actuation of this compartment; null if it was never actuated. */
  millisecondsSinceLastActuation: number | null;
}

/**
 * Outbound port for door-open facts. Separate from the command
 * response, which only ever reports that the unlock was accepted.
 */
export interface DoorEventPublisherPort {
  publishOpenDetection(event: OpenDetectionEvent): Promise<void>;
  publishUncommandedOpen(event: UncommandedOpenEvent): Promise<void>;
}
