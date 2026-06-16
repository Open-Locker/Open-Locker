import type { CommandContext, InboundCommandHandler } from '../command-dispatcher';
import { openCompartmentCommandSchema } from '../../../domain/mqtt-schemas';
import type { OpenCompartmentUseCase } from '../../../application/open-compartment';
import type { OutboundMqttPort } from '../../../ports/mqtt.port';
import type { DedupStorePort } from '../../../ports/mqtt.port';
import type { PollCompartmentStateUseCase } from '../../../application/state-publishing';

export function createOpenCompartmentHandler(deps: {
  openCompartment: OpenCompartmentUseCase;
  outbound: OutboundMqttPort;
  dedup: DedupStorePort;
  pollSnapshot: PollCompartmentStateUseCase;
}): InboundCommandHandler<unknown> {
  return {
    action: 'open_compartment',
    schema: openCompartmentCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, payload: unknown) {
      const command = openCompartmentCommandSchema.parse(payload);
      const existing = deps.dedup.getCommandRecord(command.transaction_id);
      if (existing?.status === 'completed') {
        await deps.outbound.publishCommandResponse({
          type: 'command_response',
          action: command.action,
          result: 'success',
          transaction_id: command.transaction_id,
          message: 'Duplicate command ignored (already completed).',
        });
        return;
      }
      if (existing?.status === 'in_progress') {
        return;
      }

      deps.dedup.markCommandInProgress(command.transaction_id, command.action);
      await deps.openCompartment.execute(command.data.compartment_number);
      await deps.pollSnapshot.pollAndPublish(true);
      deps.dedup.markCommandCompleted(command.transaction_id, command.action);

      await deps.outbound.publishCommandResponse({
        type: 'command_response',
        action: command.action,
        result: 'success',
        transaction_id: command.transaction_id,
        message: 'Compartment opened.',
      });
    },
  };
}
