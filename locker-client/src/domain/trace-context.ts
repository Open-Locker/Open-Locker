/**
 * The MQTT envelope property that carries W3C trace context between the backend
 * and this client.
 *
 * It is transport metadata: optional in both directions, never persisted, never
 * part of deduplication, and never used for business correlation — that stays
 * the job of `message_id` and `transaction_id`.
 */
export const TRACEPARENT_FIELD = 'traceparent';

/**
 * Reads the trace context off an inbound envelope.
 *
 * A missing or non-string value yields undefined, which starts a new trace
 * instead of rejecting the message.
 */
export function readTraceparent(payload: Record<string, unknown>): string | undefined {
  const value = payload[TRACEPARENT_FIELD];

  return typeof value === 'string' && value !== '' ? value : undefined;
}
