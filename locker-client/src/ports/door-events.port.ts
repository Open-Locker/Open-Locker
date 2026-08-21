import type { OpenDetectionOutcome } from '../domain/door-detection';

/** Reported after watching the door following an unlock pulse. */
export interface OpenDetectionEvent {
  compartmentNumber: number;
  transactionId: string;
  outcome: OpenDetectionOutcome;
  /** Time from pulse to the door being observed open; null when not applicable. */
  detectionMs: number | null;
}

/** Reported when a door opens with no relay fire that could explain it. */
export interface UncommandedOpenEvent {
  compartmentNumber: number;
  /** Age of the last relay fire on this compartment; null if it never fired. */
  millisecondsSinceLastRelayFire: number | null;
}

/**
 * Outbound port for door-open facts (ADR-0031). Separate from the command
 * response, which only ever reports that the pulse was sent.
 */
export interface DoorEventPublisherPort {
  publishOpenDetection(event: OpenDetectionEvent): Promise<void>;
  publishUncommandedOpen(event: UncommandedOpenEvent): Promise<void>;
}
