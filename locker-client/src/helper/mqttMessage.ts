import { v4 as uuidv4 } from "uuid";

export interface MQTTMessageEnvelope {
  message_id: string;
}

export function createMessageId(): string {
  return uuidv4();
}

export function withMessageId<T extends object>(
  payload: T,
): T & MQTTMessageEnvelope {
  return {
    ...payload,
    message_id: createMessageId(),
  };
}
