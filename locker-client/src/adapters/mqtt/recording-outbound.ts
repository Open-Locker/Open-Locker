import type { CommandResponseBody, DedupStorePort, OutboundMqttPort } from '../../ports/mqtt.port';

/**
 * Remembers every command response as it goes out, keyed by transaction.
 *
 * The dispatcher does not build these bodies — the handlers do, and they answer
 * through the same port — so the response is captured at the seam they all pass
 * through rather than by threading a return value back up through each handler.
 */
export function recordCommandResponses(
  inner: OutboundMqttPort,
  dedup: DedupStorePort,
): OutboundMqttPort {
  return {
    publishJson: (topic, body, options) => inner.publishJson(topic, body, options),

    async publishCommandResponse(body: CommandResponseBody): Promise<void> {
      await inner.publishCommandResponse(body);
      dedup.rememberCommandResponse(body.transaction_id, body);
    },
  };
}
