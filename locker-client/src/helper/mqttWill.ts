import { v4 as uuidv4 } from "uuid";
import type { IClientOptions } from "mqtt";

/**
 * Last Will for unexpected disconnect (AsyncAPI: locker/{uuid}/state/connection, retain=false).
 */
export function connectionLostWillOptions(lockerUuid: string): Pick<IClientOptions, "will"> {
  const topic = `locker/${lockerUuid}/state/connection`;
  const payload = JSON.stringify({
    message_id: uuidv4(),
    timestamp: new Date().toISOString(),
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
