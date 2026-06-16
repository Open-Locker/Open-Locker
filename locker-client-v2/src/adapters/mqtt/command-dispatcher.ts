import type { z } from "zod";
import { InboundProtocolGuard } from "./inbound-protocol-guard";
import type { OutboundMqttPort } from "../../ports/mqtt.port";
import { mapErrorToMqttCode } from "../../domain/errors";

export interface CommandContext {
  lockerUuid: string;
}

export interface InboundCommandHandler<TPayload> {
  readonly action: string;
  readonly schema: z.ZodType<TPayload>;
  requiresTransactionId(): boolean;
  handle(context: CommandContext, payload: TPayload): Promise<void>;
}

export class CommandDispatcher {
  private readonly handlers = new Map<string, InboundCommandHandler<unknown>>();

  constructor(
    private readonly guard: InboundProtocolGuard,
    private readonly outbound: OutboundMqttPort,
  ) {}

  register(handler: InboundCommandHandler<unknown>): void {
    this.handlers.set(handler.action, handler);
  }

  async dispatch(topic: string, rawMessage: string): Promise<void> {
    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(rawMessage) as Record<string, unknown>;
    } catch {
      return;
    }

    const action = payload.action;
    if (typeof action !== "string") {
      return;
    }

    const handler = this.handlers.get(action);
    if (!handler) {
      return;
    }

    if (
      !this.guard.allow(payload, {
        requiresTransactionId: handler.requiresTransactionId(),
      })
    ) {
      return;
    }

    const parsed = handler.schema.safeParse(payload);
    if (!parsed.success) {
      await this.outbound.publishCommandResponse({
        type: "command_response",
        action,
        result: "error",
        transaction_id:
          typeof payload.transaction_id === "string"
            ? payload.transaction_id
            : "unknown",
        error_code: "INVALID_COMMAND",
        message: "Command validation failed",
      });
      return;
    }

    const lockerUuid = extractLockerUuid(topic);
    try {
      await handler.handle({ lockerUuid }, parsed.data);
    } catch (error) {
      await this.outbound.publishCommandResponse({
        type: "command_response",
        action,
        result: "error",
        transaction_id: (parsed.data as { transaction_id: string })
          .transaction_id,
        error_code: mapErrorToMqttCode(error),
        message: error instanceof Error ? error.message : "Unknown error",
      });
    }
  }
}

function extractLockerUuid(topic: string): string {
  const parts = topic.split("/");
  return parts[1] ?? "";
}
