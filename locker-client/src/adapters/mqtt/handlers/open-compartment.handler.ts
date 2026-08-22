import type { CommandContext, InboundCommandHandler } from '../command-dispatcher';
import {
  openCompartmentCommandSchema,
  type OpenCompartmentCommand,
} from '../../../domain/mqtt-schemas';
import type { OpenCompartmentUseCase } from '../../../application/open-compartment';
import type { PollCompartmentStateUseCase } from '../../../application/state-publishing';

export function createOpenCompartmentHandler(deps: {
  openCompartment: OpenCompartmentUseCase;
  pollSnapshot: PollCompartmentStateUseCase;
}): InboundCommandHandler<OpenCompartmentCommand> {
  return {
    action: 'open_compartment',
    schema: openCompartmentCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, command: OpenCompartmentCommand) {
      await deps.openCompartment.execute(command.data.compartment_number, command.transaction_id);
      await deps.pollSnapshot.pollAndPublish(true);

      // Acknowledges the pulse only. Whether the door opened is reported
      // separately on the event channel once detection completes.
      return {
        action: command.action,
        result: 'success',
        transaction_id: command.transaction_id,
        message: 'Unlock pulse sent.',
      };
    },
  };
}
