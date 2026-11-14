/**
 * Door-open detection vocabulary and actuation correlation.
 *
 * Actuating the lock and the door actually opening are two different facts. The
 * command response reports the first; the outcomes below report the second.
 */

/**
 * Outcome of watching the door after an unlock.
 * `already_open` remains for MQTT/AsyncAPI compatibility; the client no longer
 * publishes it after actuation.
 */
export type OpenDetectionOutcome = 'opened' | 'already_open' | 'door_jammed';

/** How often the door sensor is sampled while waiting for the door to move. */
export const DOOR_DETECTION_POLL_INTERVAL_MS = 500;

/**
 * Remembers when each compartment's lock was last actuated, so a door that opens
 * can be attributed to the unlock that released it — or recognised as
 * uncommanded.
 *
 * The actuation, not the command, is the anchor: a command that errored before
 * reaching the lock never touched it and cannot explain a door opening. An
 * unlock whose outcome is unknown counts, because the lock may have moved.
 */
export class ActuationLog {
  private readonly lastActuationAtMs = new Map<number, number>();

  private readonly detecting = new Set<number>();

  recordActuation(compartmentNumber: number, atMs: number): void {
    this.lastActuationAtMs.set(compartmentNumber, atMs);
  }

  lastActuationAt(compartmentNumber: number): number | null {
    return this.lastActuationAtMs.get(compartmentNumber) ?? null;
  }

  /** Milliseconds since this compartment's lock was last actuated, or null if never. */
  millisecondsSinceActuation(compartmentNumber: number, nowMs: number): number | null {
    const actuatedAt = this.lastActuationAt(compartmentNumber);

    return actuatedAt === null ? null : nowMs - actuatedAt;
  }

  beginDetection(compartmentNumber: number): void {
    this.detecting.add(compartmentNumber);
  }

  endDetection(compartmentNumber: number): void {
    this.detecting.delete(compartmentNumber);
  }

  /**
   * True while a detection window owns this compartment's next door opening,
   * so the state poller does not also report it as uncommanded.
   */
  isDetecting(compartmentNumber: number): boolean {
    return this.detecting.has(compartmentNumber);
  }

  clear(): void {
    this.lastActuationAtMs.clear();
    this.detecting.clear();
  }
}
