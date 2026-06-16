import { randomUUID } from "crypto";

export interface MqttEnvelope {
  message_id: string;
  timestamp: string;
}

export function createEnvelope(
  body: Record<string, unknown>,
  nowIso: () => string = () => new Date().toISOString(),
): Record<string, unknown> {
  const envelope: Record<string, unknown> = { ...body };

  if (!("message_id" in envelope) || typeof envelope.message_id !== "string") {
    envelope.message_id = randomUUID();
  }

  if (!("timestamp" in envelope) || typeof envelope.timestamp !== "string") {
    envelope.timestamp = nowIso();
  }

  return envelope;
}

export function serializeOutboundPayload(
  body: Record<string, unknown>,
  nowIso?: () => string,
): string {
  return JSON.stringify(createEnvelope(body, nowIso));
}
