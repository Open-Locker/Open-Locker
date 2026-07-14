import type {
  CommandResponseBody,
  OutboundMqttPort,
  OutboundPublishOptions,
} from '../../ports/mqtt.port';
import { serializeOutboundPayload } from './outbound-envelope';

export class OutboundMqttAdapter implements OutboundMqttPort {
  constructor(
    private readonly publishRaw: (
      topic: string,
      payload: string,
      options?: OutboundPublishOptions,
    ) => Promise<void>,
    private readonly responseTopic: string,
    private readonly nowIso: () => string = () => new Date().toISOString(),
  ) {}

  async publishJson(
    topic: string,
    body: Record<string, unknown>,
    options?: OutboundPublishOptions,
  ): Promise<void> {
    const payload = serializeOutboundPayload(body, this.nowIso);
    await this.publishRaw(topic, payload, options);
  }

  async publishCommandResponse(body: CommandResponseBody): Promise<void> {
    await this.publishJson(this.responseTopic, { ...body }, { qos: 1 });
  }
}
