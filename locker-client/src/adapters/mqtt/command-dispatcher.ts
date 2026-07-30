import type { z } from 'zod';
import { InboundProtocolGuard } from './inbound-protocol-guard';
import type { DedupStorePort, OutboundMqttPort } from '../../ports/mqtt.port';
import { noopTracing, type TracingPort } from '../../ports/tracing.port';
import { mapErrorToMqttCode, MqttErrorCode } from '../../domain/errors';
import { mqttSpanAttributes, spanDestination } from '../../domain/mqtt-span-attributes';
import { readTraceparent } from '../../domain/trace-context';
import { formatZodValidationError } from '../../domain/mqtt-parsing';
import { logger } from '../../infrastructure/logging';

export interface CommandContext {
  lockerUuid: string;
}

export interface InboundCommandHandler<TPayload> {
  readonly action: string;
  readonly schema: z.ZodType<TPayload>;
  requiresTransactionId(): boolean;
  handle(context: CommandContext, payload: TPayload): Promise<void>;
}

interface TransactionCommandPayload {
  transaction_id: string;
}

/** Stands in for the required `action` field when the command never carried one. */
const UNKNOWN_ACTION_LABEL = 'unknown';

export class CommandDispatcher {
  private readonly handlers = new Map<string, InboundCommandHandler<unknown>>();

  constructor(
    private readonly guard: InboundProtocolGuard,
    private readonly outbound: OutboundMqttPort,
    private readonly dedup: DedupStorePort,
    private readonly tracing: TracingPort = noopTracing,
  ) {}

  register(handler: InboundCommandHandler<unknown>): void {
    this.handlers.set(handler.action, handler);
  }

  async dispatch(topic: string, rawMessage: string): Promise<void> {
    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(rawMessage) as Record<string, unknown>;
    } catch {
      // Unparseable messages carry no trace context to continue, so this one
      // stays outside the span.
      logger.warn('Dropped inbound MQTT command with invalid JSON', { topic });
      return;
    }

    await this.tracing.inSpan(
      `mqtt process ${spanDestination(topic)}`,
      {
        kind: 'consumer',
        attributes: mqttSpanAttributes(topic, payload),
        // Absent or malformed context starts a new trace rather than
        // rejecting the command.
        parentTraceparent: readTraceparent(payload),
      },
      () => this.dispatchParsed(topic, payload),
    );
  }

  private async dispatchParsed(topic: string, payload: Record<string, unknown>): Promise<void> {
    const action = payload.action;
    if (typeof action !== 'string') {
      logger.warn('Dropped inbound MQTT command without action', { topic });
      // The envelope requires a non-empty action, and there is none to echo.
      await this.rejectCommand(
        UNKNOWN_ACTION_LABEL,
        payload,
        MqttErrorCode.INVALID_COMMAND,
        'Command is missing a valid action',
      );
      return;
    }

    const handler = this.handlers.get(action);
    if (!handler) {
      logger.warn('Dropped inbound MQTT command with unknown action', { topic, action });
      await this.rejectCommand(
        action,
        payload,
        MqttErrorCode.UNKNOWN_ACTION,
        `No handler is registered for action "${action}"`,
      );
      return;
    }

    const guardResult = this.guard.allow(payload, {
      requiresTransactionId: handler.requiresTransactionId(),
    });
    if (!guardResult.ok) {
      logger.warn('Dropped inbound MQTT command due to protocol guard', {
        topic,
        action,
        reason: guardResult.reason,
      });

      if (guardResult.reason === 'missing_message_id') {
        await this.rejectCommand(
          action,
          payload,
          MqttErrorCode.MISSING_MESSAGE_ID,
          'Command is missing message_id',
        );
      }

      if (guardResult.reason === 'duplicate_message_id') {
        // A redelivery, which normally means the first reply never landed.
        // Sending it again is what unblocks the backend; sending an error
        // instead would contradict the answer it may already have acted on.
        await this.replayStoredResponse(payload.transaction_id);
      }

      // `missing_transaction_id` stays silent: with no correlation id there is
      // nothing to answer on, and a synthesised one would resolve some other
      // pending command.
      return;
    }

    const parsed = handler.schema.safeParse(payload);
    if (!parsed.success) {
      logger.warn('Rejected inbound MQTT command due to schema validation', {
        topic,
        action,
        validationErrors: formatZodValidationError(parsed.error),
      });
      await this.outbound.publishCommandResponse({
        action,
        result: 'error',
        transaction_id:
          typeof payload.transaction_id === 'string' ? payload.transaction_id : 'unknown',
        error_code: 'INVALID_COMMAND',
        message: 'Command validation failed',
      });
      return;
    }

    const lockerUuid = extractLockerUuid(topic);
    const command = parsed.data as TransactionCommandPayload;

    if (handler.requiresTransactionId()) {
      const dedupResult = await this.guardTransactionExecution(action, command.transaction_id);
      if (dedupResult === 'duplicate_completed') {
        await this.replayStoredResponse(command.transaction_id);
        return;
      }
      if (dedupResult === 'duplicate_in_progress') {
        // Nothing to replay yet, and there is no `result` value for work that
        // is still running — the envelope allows success or error only. The
        // execution already under way publishes the real answer when it lands.
        return;
      }
    }

    try {
      await handler.handle({ lockerUuid }, parsed.data);
      if (handler.requiresTransactionId()) {
        this.dedup.markCommandCompleted(command.transaction_id, action);
      }
    } catch (error) {
      if (handler.requiresTransactionId()) {
        this.dedup.markCommandCompleted(command.transaction_id, action);
      }
      await this.outbound.publishCommandResponse({
        action,
        result: 'error',
        transaction_id: command.transaction_id,
        error_code: mapErrorToMqttCode(error),
        message: error instanceof Error ? error.message : 'Unknown error',
      });
    }
  }

  /**
   * Answers a rejected command so the backend stops waiting on it.
   *
   * Only possible when the payload carries a transaction_id: that is the field
   * the backend correlates replies with, and inventing one would resolve some
   * unrelated pending command. Without it the rejection stays a log line.
   */
  private async rejectCommand(
    action: string,
    payload: Record<string, unknown>,
    errorCode: MqttErrorCode,
    message: string,
  ): Promise<void> {
    const transactionId = payload.transaction_id;
    if (typeof transactionId !== 'string' || transactionId.trim() === '') {
      return;
    }

    await this.outbound.publishCommandResponse({
      action,
      result: 'error',
      transaction_id: transactionId,
      error_code: errorCode,
      message,
    });
  }

  /**
   * Re-sends the answer a transaction already got.
   *
   * The envelope gets a fresh message_id on the way out, which matters: the
   * backend discards inbound duplicates by message_id, so a byte-identical
   * replay would be thrown away by the very guard it is meant to satisfy. The
   * transaction_id is unchanged, so a backend that did receive the original
   * recognises this as the same answer and takes no second action.
   */
  private async replayStoredResponse(transactionId: unknown): Promise<void> {
    if (typeof transactionId !== 'string' || transactionId.trim() === '') {
      return;
    }

    const stored = this.dedup.getCommandResponse(transactionId);
    if (!stored) {
      // Reached when the first delivery was deduplicated before it produced an
      // answer, or when retained state was lost with a restart.
      logger.warn('No stored response to replay for duplicate command', { transactionId });
      return;
    }

    logger.info('Replaying stored response for duplicate command', {
      transactionId,
      action: stored.action,
      result: stored.result,
    });
    await this.outbound.publishCommandResponse(stored);
  }

  private async guardTransactionExecution(
    action: string,
    transactionId: string,
  ): Promise<'ready' | 'duplicate_completed' | 'duplicate_in_progress'> {
    const existing = this.dedup.getCommandRecord(transactionId);
    if (existing?.status === 'completed') {
      return 'duplicate_completed';
    }
    if (existing?.status === 'in_progress') {
      return 'duplicate_in_progress';
    }

    this.dedup.markCommandInProgress(transactionId, action);
    return 'ready';
  }
}

function extractLockerUuid(topic: string): string {
  const parts = topic.split('/');
  return parts[1] ?? '';
}
