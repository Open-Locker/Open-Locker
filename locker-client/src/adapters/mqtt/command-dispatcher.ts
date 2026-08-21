import type { z } from 'zod';
import { InboundProtocolGuard } from './inbound-protocol-guard';
import type {
  CommandResponseBody,
  DedupStorePort,
  OutboundMqttPort,
  StoredCommandResponse,
} from '../../ports/mqtt.port';
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
  handle(context: CommandContext, payload: TPayload): Promise<CommandResponseBody>;
}

interface TransactionCommandPayload {
  transaction_id: string;
}

interface ResolvedCommand {
  action: string;
  handler: InboundCommandHandler<unknown>;
  payload: Record<string, unknown>;
}

interface ValidatedCommand extends ResolvedCommand {
  data: unknown;
  transactionId: string;
}

export class CommandDispatcher {
  private readonly handlers = new Map<string, InboundCommandHandler<unknown>>();
  private flushInFlight: Promise<void> | null = null;
  private flushRequested = false;

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
    const resolved = this.parseAndResolveHandler(topic, rawMessage);
    if (!resolved) {
      return;
    }

    await this.tracing.inSpan(
      `mqtt process ${spanDestination(topic)}`,
      {
        kind: 'consumer',
        attributes: mqttSpanAttributes(topic, resolved.payload),
        // Absent or malformed context starts a new trace rather than
        // rejecting the command.
        parentTraceparent: readTraceparent(resolved.payload),
      },
      () => this.dispatchResolved(topic, resolved),
    );
  }

  private async dispatchResolved(topic: string, resolved: ResolvedCommand): Promise<void> {
    const command = await this.validateCommand(topic, resolved);
    if (!command || (await this.handleDuplicateCommand(command))) {
      return;
    }

    const response = await this.executeCommand(topic, command);
    await this.finalizeCommand(command, response);
  }

  recoverInterruptedCommands(): void {
    for (const { transactionId, record } of this.dedup.listCommandRecords()) {
      if (record.status !== 'in_progress') {
        continue;
      }
      this.dedup.markCommandCompleted(transactionId, record.action, {
        result: 'error',
        error_code: MqttErrorCode.UNKNOWN_ERROR,
        message: 'Command outcome is unknown after locker-client restart.',
      });
    }
  }

  flushPendingResponses(): Promise<void> {
    this.flushRequested = true;
    if (this.flushInFlight) {
      return this.flushInFlight;
    }

    this.flushInFlight = this.flushPendingResponsesUntilIdle().finally(() => {
      this.flushInFlight = null;
    });
    return this.flushInFlight;
  }

  private parseAndResolveHandler(topic: string, rawMessage: string): ResolvedCommand | null {
    let payload: Record<string, unknown>;
    try {
      payload = JSON.parse(rawMessage) as Record<string, unknown>;
    } catch {
      // Unparseable messages carry no trace context to continue, so this one
      // stays outside the span.
      logger.warn('Dropped inbound MQTT command with invalid JSON', { topic });
      return null;
    }

    const action = payload.action;
    if (typeof action !== 'string') {
      logger.warn('Dropped inbound MQTT command without action', { topic });
      return null;
    }

    const handler = this.handlers.get(action);
    if (!handler) {
      logger.warn('Dropped inbound MQTT command with unknown action', { topic, action });
      return null;
    }

    return { action, handler, payload };
  }

  private async validateCommand(
    topic: string,
    command: ResolvedCommand,
  ): Promise<ValidatedCommand | null> {
    const { action, handler, payload } = command;
    const guardResult = this.guard.allow(payload, {
      requiresTransactionId: handler.requiresTransactionId(),
    });
    if (!guardResult.ok) {
      logger.warn('Dropped inbound MQTT command due to protocol guard', {
        topic,
        action,
        reason: guardResult.reason,
      });
      return null;
    }

    const parsed = handler.schema.safeParse(payload);
    if (!parsed.success) {
      logger.warn('Rejected inbound MQTT command due to schema validation', {
        topic,
        action,
        validationErrors: formatZodValidationError(parsed.error),
      });
      await this.handleInvalidCommand(action, payload);
      return null;
    }

    return {
      ...command,
      data: parsed.data,
      transactionId: (parsed.data as TransactionCommandPayload).transaction_id,
    };
  }

  private async handleInvalidCommand(
    action: string,
    payload: Record<string, unknown>,
  ): Promise<void> {
    const transactionId =
      typeof payload.transaction_id === 'string' && payload.transaction_id.trim() !== ''
        ? payload.transaction_id
        : null;
    const response: CommandResponseBody = {
      action,
      result: 'error',
      transaction_id: transactionId ?? 'unknown',
      error_code: MqttErrorCode.INVALID_COMMAND,
      message: 'Command validation failed',
    };

    if (!transactionId) {
      await this.outbound.publishCommandResponse(response);
      return;
    }

    const existing = this.dedup.getCommandRecord(transactionId);
    if (existing?.status === 'completed') {
      if (existing.response) {
        await this.replayFinalResponse(transactionId, existing.action, existing.response);
      } else {
        logger.warn('Cannot replay legacy completed command without stored response', {
          action,
          transactionId,
        });
      }
      return;
    }
    if (existing?.status === 'in_progress') {
      return;
    }

    this.dedup.markCommandCompleted(transactionId, action, toStoredCommandResponse(response));
    await this.publishFinalResponse(transactionId, response);
  }

  private async handleDuplicateCommand(command: ValidatedCommand): Promise<boolean> {
    const { action, handler, transactionId } = command;
    if (handler.requiresTransactionId()) {
      const dedupResult = await this.guardTransactionExecution(action, transactionId);
      if (dedupResult === 'duplicate_completed') {
        const existing = this.dedup.getCommandRecord(transactionId);
        if (existing?.response) {
          await this.replayFinalResponse(transactionId, existing.action, existing.response);
        } else {
          logger.warn('Cannot replay legacy completed command without stored response', {
            action,
            transactionId,
          });
        }
        return true;
      }
      if (dedupResult === 'duplicate_in_progress') {
        return true;
      }
    }

    return false;
  }

  private async executeCommand(
    topic: string,
    command: ValidatedCommand,
  ): Promise<CommandResponseBody> {
    const { action, handler, data, transactionId } = command;
    try {
      return await handler.handle({ lockerUuid: extractLockerUuid(topic) }, data);
    } catch (error) {
      return {
        action,
        result: 'error',
        transaction_id: transactionId,
        error_code: mapErrorToMqttCode(error),
        message: error instanceof Error ? error.message : 'Unknown error',
      };
    }
  }

  private async finalizeCommand(
    command: ValidatedCommand,
    response: CommandResponseBody,
  ): Promise<void> {
    const { action, handler, transactionId } = command;
    if (handler.requiresTransactionId()) {
      this.dedup.markCommandCompleted(transactionId, action, toStoredCommandResponse(response));
    }
    await this.publishFinalResponse(transactionId, response);
  }

  private async flushPendingResponsesInternal(): Promise<void> {
    const pending = this.dedup
      .listCommandRecords()
      .filter(
        ({ record }) =>
          record.status === 'completed' && record.response && !record.responseDeliveredAt,
      );
    for (const { transactionId, record } of pending) {
      if (record.response) {
        await this.publishStoredResponse(transactionId, record.action, record.response);
      }
    }
  }

  private async flushPendingResponsesUntilIdle(): Promise<void> {
    while (this.flushRequested) {
      this.flushRequested = false;
      await this.flushPendingResponsesInternal();
    }
  }

  private async replayFinalResponse(
    transactionId: string,
    action: string,
    response: StoredCommandResponse,
  ): Promise<void> {
    this.dedup.markCommandResponsePending(transactionId);
    await this.publishStoredResponse(transactionId, action, response);
  }

  private async publishStoredResponse(
    transactionId: string,
    action: string,
    response: StoredCommandResponse,
  ): Promise<void> {
    await this.publishFinalResponse(transactionId, {
      action,
      transaction_id: transactionId,
      ...response,
    });
  }

  private async publishFinalResponse(
    transactionId: string,
    response: CommandResponseBody,
  ): Promise<void> {
    try {
      await this.outbound.publishCommandResponse(response);
      this.dedup.markCommandResponseDelivered(transactionId);
    } catch (error) {
      logger.warn('MQTT command response remains pending', {
        action: response.action,
        transactionId,
        error: error instanceof Error ? error.message : 'Unknown publish error',
      });
    }
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

function toStoredCommandResponse(response: CommandResponseBody): StoredCommandResponse {
  const { action: _action, transaction_id: _transactionId, ...storedResponse } = response;
  return storedResponse;
}
