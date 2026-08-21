/**
 * Door-open detection vocabulary and relay-fire correlation (ADR-0031).
 *
 * Firing the relay and the door actually opening are two different facts. The
 * command response reports the first; the outcomes below report the second.
 */

/** Outcome of watching the door after an unlock pulse. */
export type OpenDetectionOutcome = 'opened' | 'already_open' | 'door_jammed';

/** How often the door sensor is sampled while waiting for the door to move. */
export const DOOR_DETECTION_POLL_INTERVAL_MS = 500;

/**
 * Remembers when each compartment's relay last fired, so a door that opens can
 * be attributed to the pulse that released it — or recognised as uncommanded.
 *
 * The relay fire, not the command, is the anchor: a command that errored before
 * reaching the lock never touched it and cannot explain a door opening.
 */
export class RelayFireLog {
  private readonly lastFireAtMs = new Map<number, number>();

  private readonly detecting = new Set<number>();

  recordFire(compartmentNumber: number, atMs: number): void {
    this.lastFireAtMs.set(compartmentNumber, atMs);
  }

  lastFireAt(compartmentNumber: number): number | null {
    return this.lastFireAtMs.get(compartmentNumber) ?? null;
  }

  /** Milliseconds since this compartment's relay last fired, or null if never. */
  millisecondsSinceFire(compartmentNumber: number, nowMs: number): number | null {
    const fireAt = this.lastFireAt(compartmentNumber);

    return fireAt === null ? null : nowMs - fireAt;
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
    this.lastFireAtMs.clear();
    this.detecting.clear();
  }
}
