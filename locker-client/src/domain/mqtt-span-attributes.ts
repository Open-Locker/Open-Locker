import type { SpanAttributes } from '../ports/tracing.port';
import {
  ACTION,
  COMMAND_ID,
  COMPARTMENT_NUMBER,
  EVENT,
  LOCKER_UUID,
  MESSAGING_DESTINATION,
  MESSAGING_MESSAGE_ID,
  MESSAGING_SYSTEM,
  MESSAGING_SYSTEM_MQTT,
  TRANSACTION_ID,
} from './trace-attributes';

const REGISTRATION_PREFIX = 'locker/register/';
const REGISTRATION_DESTINATION = 'locker/register/{token}';

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Span-safe destination for a topic. Registration topics carry the provisioning
 * token in their path, so the token is templated out of both the attribute and
 * the span name. Provisioning does not currently publish through a traced path,
 * but a secret must not be one refactor away from a span.
 */
export function spanDestination(topic: string): string {
  return topic.startsWith(REGISTRATION_PREFIX) ? REGISTRATION_DESTINATION : topic;
}

/**
 * Whether a topic is worth tracing.
 *
 * Heartbeats go out on a timer forever and retained snapshots are republished
 * on every door change and every reconnect. Tracing them would bury the flows
 * tracing exists for, and at fleet scale they would dominate the trace backend
 * entirely. The backend declines to trace the same two topics on receipt; this
 * is the other half of that decision, and it also keeps the `traceparent` field
 * off the chattiest messages on the wire.
 *
 * The door-state facts themselves stay visible as stored events. Only their
 * transport timing goes unmeasured.
 */
export function tracesTopic(topic: string): boolean {
  return !topic.includes('/state/');
}

/**
 * Derives span attributes from an MQTT topic and envelope. Only known fields
 * are read — payload bodies are never attached to spans.
 */
export function mqttSpanAttributes(topic: string, body: Record<string, unknown>): SpanAttributes {
  const data = isRecord(body.data) ? body.data : {};

  return {
    [MESSAGING_SYSTEM]: MESSAGING_SYSTEM_MQTT,
    [MESSAGING_DESTINATION]: spanDestination(topic),
    [MESSAGING_MESSAGE_ID]: scalar(body.message_id),
    // Events carry the transaction inside `data`; commands carry it top level.
    [TRANSACTION_ID]: scalar(body.transaction_id) ?? scalar(data.transaction_id),
    [COMMAND_ID]: scalar(body.command_id) ?? scalar(data.command_id),
    [ACTION]: scalar(body.action),
    [EVENT]: scalar(body.event),
    [COMPARTMENT_NUMBER]: scalar(data.compartment_number) ?? scalar(body.compartment_number),
    [LOCKER_UUID]: lockerUuidFromTopic(topic),
  };
}

/**
 * Device topics are `locker/{uuid}/...`, but `locker/` is also the prefix for
 * registration and provisioning-reply topics, whose second segment is a literal
 * word or a client id. Only an actual UUID is reported as one.
 */
function lockerUuidFromTopic(topic: string): string | undefined {
  const segments = topic.split('/');

  if (segments[0] !== 'locker') {
    return undefined;
  }

  const candidate = segments[1];

  return candidate && UUID_PATTERN.test(candidate) ? candidate : undefined;
}

function scalar(value: unknown): string | number | boolean | undefined {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? value
    : undefined;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
