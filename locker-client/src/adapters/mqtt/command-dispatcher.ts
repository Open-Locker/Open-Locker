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

/** Stands in for the required `action` field when the command never carried one. */
const UNKNOWN_ACTION_LABEL = 'unknown';

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
  private closing = false;

  constructor(
    private readonly guard: InboundProtocolGuard,
    private readonly outbound: OutboundMqttPort,
    private readonly dedup: DedupStorePort,
    private readonly tracing: TracingPort = noopTracing,
  ) {}

  register(handler: InboundCommandHandler<unknown>): void {
    this.handlers.set(handler.action, handler);
  }

  /**
   * Stop accepting commands, ahead of the transport going away.
   *
   * Answering is the point: a command dropped in silence leaves the backend
   * waiting for a response that will never come, while a refusal lets it fail
   * the request now and let the user retry against a client that is running.
   */
  beginClosing(): void {
    this.closing = true;
  }

  async dispatch(topic: string, rawMessage: string): Promise<void> {
    // Read once, at arrival. Parsing is async, so checking later would refuse a
    // command that reached us before shutdown began — the very work the drain
    // is meant to let finish.
    const arrivedWhileClosing = this.closing;

    const resolved = await this.parseAndResolveHandler(topic, rawMessage);
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
      () => this.dispatchResolved(topic, resolved, arrivedWhileClosing),
    );
  }

  private async dispatchResolved(
    topic: string,
    resolved: ResolvedCommand,
    arrivedWhileClosing: boolean,
  ): Promise<void> {
    // Validation first, even while closing: it is what routes a redelivery to
    // its stored response. Refusing ahead of that would answer a command whose
    // relay already fired with an error, contradicting a reply the backend may
    // have acted on — a worse failure than the silence this gate replaced.
    const command = await this.validateCommand(topic, resolved);
    if (!command || (await this.handleDuplicateCommand(command, !arrivedWhileClosing))) {
      return;
    }

    // Refuse here: past the duplicate lookup, so a redelivery still replays its
    // stored answer, but before the command is claimed as running. Marking a
    // refused command in progress would leave a record for a relay that never
    // fired, and restart recovery would then publish a second, contradictory
    // answer for it.
    if (arrivedWhileClosing) {
      logger.warn('Refused inbound MQTT command: shutting down', {
        topic,
        action: resolved.action,
      });

      await this.rejectCommand(
        resolved.action,
        resolved.payload,
        MqttErrorCode.SHUTTING_DOWN,
        'locker-client is shutting down and did not run this command.',
      );

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

  private async parseAndResolveHandler(
    topic: string,
    rawMessage: string,
  ): Promise<ResolvedCommand | null> {
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
      // The envelope requires a non-empty action, and there is none to echo.
      await this.rejectCommand(
        UNKNOWN_ACTION_LABEL,
        payload,
        MqttErrorCode.INVALID_COMMAND,
        'Command is missing a valid action',
      );
      return null;
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

  private async handleDuplicateCommand(
    command: ValidatedCommand,
    claim: boolean,
  ): Promise<boolean> {
    const { action, handler, transactionId } = command;
    if (handler.requiresTransactionId()) {
      const dedupResult = await this.guardTransactionExecution(action, transactionId, claim);
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
        // Nothing to replay yet, and there is no `result` value for work that
        // is still running — the envelope allows success or error only. The
        // execution already under way publishes the real answer when it lands.
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

    const record = this.dedup.getCommandRecord(transactionId);
    if (!record?.response) {
      // Reached when the first delivery was deduplicated before it produced an
      // answer, or when retained state was lost with a restart.
      logger.warn('No stored response to replay for duplicate command', { transactionId });
      return;
    }

    logger.info('Replaying stored response for duplicate command', {
      transactionId,
      action: record.action,
      result: record.response.result,
    });
    // Goes through the pending/delivered path so a failed replay is retried
    // rather than silently lost.
    await this.replayFinalResponse(transactionId, record.action, record.response);
  }

  /**
   * Looks the transaction up and, when the caller intends to run it, claims it
   * in the same synchronous block.
   *
   * The lookup and the claim must not be separated by an await. Two deliveries
   * of one transaction id would otherwise both read "ready" and both execute —
   * the same door opening twice on a single request. `claim` is false only when
   * the caller is going to refuse the command, so nothing is recorded for a
   * relay that never fires.
   */
  private async guardTransactionExecution(
    action: string,
    transactionId: string,
    claim: boolean,
  ): Promise<'ready' | 'duplicate_completed' | 'duplicate_in_progress'> {
    const existing = this.dedup.getCommandRecord(transactionId);
    if (existing?.status === 'completed') {
      return 'duplicate_completed';
    }
    if (existing?.status === 'in_progress') {
      return 'duplicate_in_progress';
    }

    if (claim) {
      this.dedup.markCommandInProgress(transactionId, action);
    }

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
