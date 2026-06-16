import { randomUUID } from "crypto";
import type { IClientOptions } from "mqtt";

/**
 * Last Will for unexpected disconnect (AsyncAPI: locker/{uuid}/state/connection).
 */
export function connectionLostWillOptions(
  lockerUuid: string,
  nowIso: () => string = () => new Date().toISOString(),
): Pick<IClientOptions, "will"> {
  const topic = `locker/${lockerUuid}/state/connection`;
  const payload = JSON.stringify({
    message_id: randomUUID(),
    timestamp: nowIso(),
    status: "offline",
    reason: "mqtt_last_will",
  });

  return {
    will: {
      topic,
      payload,
      qos: 1,
      retain: false,
    },
  };
}
