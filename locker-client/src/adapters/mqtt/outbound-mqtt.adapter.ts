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
import { noopLogger, type LoggerPort } from '../../ports/logging.port';
import { createEnvelope } from './outbound-envelope';

export class OutboundMqttAdapter implements OutboundMqttPort {
  constructor(
    private readonly publishRaw: (
      topic: string,
      payload: string,
      options?: OutboundPublishOptions,
    ) => Promise<boolean>,
    private readonly responseTopic: string,
    private readonly nowIso: () => string = () => new Date().toISOString(),
    private readonly tracing: TracingPort = noopTracing,
    private readonly log: LoggerPort = noopLogger,
  ) {}

  async publishJson(
    topic: string,
    body: Record<string, unknown>,
    options?: OutboundPublishOptions,
  ): Promise<void> {
    // Built before the span so the span can report the message id it will send.
    const envelope = createEnvelope(body, this.nowIso);

    if (!tracesTopic(topic)) {
      const delivered = await this.publishRaw(topic, JSON.stringify(envelope), options);
      this.reportIfDropped(topic, envelope, delivered);

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

        const delivered = await this.publishRaw(
          topic,
          JSON.stringify(
            traceparent ? { ...envelope, [TRACEPARENT_FIELD]: traceparent } : envelope,
          ),
          options,
        );
        this.reportIfDropped(topic, envelope, delivered);
      },
    );
  }

  /**
   * The transport already logs that it skipped a publish, but only this layer
   * knows which message was lost. A dropped response is why a backend
   * transaction hangs, so it is named here rather than inferred from a topic.
   */
  private reportIfDropped(
    topic: string,
    envelope: Record<string, unknown>,
    delivered: boolean,
  ): void {
    if (delivered) {
      return;
    }

    this.log.error('Outbound MQTT message dropped before reaching the broker', {
      topic,
      messageId: envelope.message_id,
      transactionId: envelope.transaction_id,
      action: envelope.action,
    });
  }

  async publishCommandResponse(body: CommandResponseBody): Promise<void> {
    await this.publishJson(this.responseTopic, { ...body }, { qos: 1 });
  }
}
