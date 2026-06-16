import type { z } from 'zod';
import { InboundProtocolGuard } from './inbound-protocol-guard';
import type { DedupStorePort, OutboundMqttPort } from '../../ports/mqtt.port';
import { mapErrorToMqttCode } from '../../domain/errors';

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

export class CommandDispatcher {
  private readonly handlers = new Map<string, InboundCommandHandler<unknown>>();

  constructor(
    private readonly guard: InboundProtocolGuard,
    private readonly outbound: OutboundMqttPort,
    private readonly dedup: DedupStorePort,
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
    if (typeof action !== 'string') {
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
        type: 'command_response',
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
        if (action === 'open_compartment') {
          await this.outbound.publishCommandResponse({
            type: 'command_response',
            action,
            result: 'success',
            transaction_id: command.transaction_id,
            message: 'Duplicate command ignored (already completed).',
          });
        }
        return;
      }
      if (dedupResult === 'duplicate_in_progress') {
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
        type: 'command_response',
        action,
        result: 'error',
        transaction_id: command.transaction_id,
        error_code: mapErrorToMqttCode(error),
        message: error instanceof Error ? error.message : 'Unknown error',
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
