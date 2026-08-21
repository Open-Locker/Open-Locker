import type {
  CommandResponseBody,
  OutboundMqttPort,
  OutboundPublishOptions,
} from '../../ports/mqtt.port';
import { noopTracing, type TracingPort } from '../../ports/tracing.port';
import {
  mqttSpanAttributes,
  spanDestination,
  tracesTopic,
} from '../../domain/mqtt-span-attributes';
import { TRACEPARENT_FIELD } from '../../domain/trace-context';
import { createEnvelope } from './outbound-envelope';

export class OutboundMqttAdapter implements OutboundMqttPort {
  constructor(
    private readonly publishRaw: (
      topic: string,
      payload: string,
      options?: OutboundPublishOptions,
    ) => Promise<void>,
    private readonly responseTopic: string,
    private readonly nowIso: () => string = () => new Date().toISOString(),
    private readonly tracing: TracingPort = noopTracing,
  ) {}

  async publishJson(
    topic: string,
    body: Record<string, unknown>,
    options?: OutboundPublishOptions,
  ): Promise<void> {
    // Built before the span so the span can report the message id it will send.
    const envelope = createEnvelope(body, this.nowIso);

    if (!tracesTopic(topic)) {
      await this.publishRaw(topic, JSON.stringify(envelope), options);

      return;
    }

    await this.tracing.inSpan(
      `mqtt publish ${spanDestination(topic)}`,
      { kind: 'producer', attributes: mqttSpanAttributes(topic, envelope) },
      async () => {
        // Stamped inside the span so the receiver continues from this publish
        // rather than from whatever started the flow. Undefined when tracing is
        // off, which leaves the payload exactly as it was before.
        const traceparent = this.tracing.currentTraceparent();

        await this.publishRaw(
          topic,
          JSON.stringify(
            traceparent ? { ...envelope, [TRACEPARENT_FIELD]: traceparent } : envelope,
          ),
          options,
        );
      },
    );
  }

  async publishCommandResponse(body: CommandResponseBody): Promise<void> {
    await this.publishJson(this.responseTopic, { ...body }, { qos: 1 });
  }
}
