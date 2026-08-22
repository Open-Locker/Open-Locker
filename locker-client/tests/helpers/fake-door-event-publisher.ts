import type {
  DoorEventPublisherPort,
  OpenDetectionEvent,
  UncommandedOpenEvent,
} from '../../src/ports/door-events.port';

export class FakeDoorEventPublisher implements DoorEventPublisherPort {
  readonly detections: OpenDetectionEvent[] = [];

  readonly uncommandedOpens: UncommandedOpenEvent[] = [];

  async publishOpenDetection(event: OpenDetectionEvent): Promise<void> {
    this.detections.push(event);
  }

  async publishUncommandedOpen(event: UncommandedOpenEvent): Promise<void> {
    this.uncommandedOpens.push(event);
  }

  lastDetection(): OpenDetectionEvent | undefined {
    return this.detections.at(-1);
  }
}
