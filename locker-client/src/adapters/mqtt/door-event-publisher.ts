import type {
  DoorEventPublisherPort,
  OpenDetectionEvent,
  UncommandedOpenEvent,
} from '../../ports/door-events.port';
import type { OutboundMqttPort } from '../../ports/mqtt.port';
import { MqttErrorCode } from '../../domain/errors';

const OPEN_DETECTED_EVENT = 'compartment_open_detected';
const OPEN_FAILED_EVENT = 'compartment_open_failed';
const UNCOMMANDED_OPEN_EVENT = 'compartment_uncommanded_open';

/**
 * Publishes door-open facts on the device event channel.
 *
 * Deliberately not the response channel: a second `command_response` for the
 * same transaction would break inbound dedup and make the response's
 * meaning ambiguous.
 */
export class MqttDoorEventPublisher implements DoorEventPublisherPort {
  constructor(
    private readonly outbound: OutboundMqttPort,
    private readonly eventTopic: string,
  ) {}

  async publishOpenDetection(event: OpenDetectionEvent): Promise<void> {
    const jammed = event.outcome === 'door_jammed';

    await this.outbound.publishJson(
      this.eventTopic,
      {
        event: jammed ? OPEN_FAILED_EVENT : OPEN_DETECTED_EVENT,
        data: {
          compartment_number: event.compartmentNumber,
          transaction_id: event.transactionId,
          outcome: event.outcome,
          ...(jammed ? { error_code: MqttErrorCode.DOOR_JAMMED } : {}),
          ...(event.detectionMs === null ? {} : { detection_ms: event.detectionMs }),
        },
      },
      { qos: 1 },
    );
  }

  async publishUncommandedOpen(event: UncommandedOpenEvent): Promise<void> {
    await this.outbound.publishJson(
      this.eventTopic,
      {
        event: UNCOMMANDED_OPEN_EVENT,
        data: {
          compartment_number: event.compartmentNumber,
          ...(event.millisecondsSinceLastRelayFire === null
            ? {}
            : { milliseconds_since_last_relay_fire: event.millisecondsSinceLastRelayFire }),
        },
      },
      { qos: 1 },
    );
  }
}
