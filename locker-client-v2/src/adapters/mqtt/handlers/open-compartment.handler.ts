import type { CommandContext, InboundCommandHandler } from '../command-dispatcher';
import { openCompartmentCommandSchema } from '../../../domain/mqtt-schemas';
import type { OpenCompartmentUseCase } from '../../../application/open-compartment';
import type { OutboundMqttPort } from '../../../ports/mqtt.port';
import type { PollCompartmentStateUseCase } from '../../../application/state-publishing';

export function createOpenCompartmentHandler(deps: {
  openCompartment: OpenCompartmentUseCase;
  outbound: OutboundMqttPort;
  pollSnapshot: PollCompartmentStateUseCase;
}): InboundCommandHandler<unknown> {
  return {
    action: 'open_compartment',
    schema: openCompartmentCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, payload: unknown) {
      const command = openCompartmentCommandSchema.parse(payload);

      await deps.openCompartment.execute(command.data.compartment_number);
      await deps.pollSnapshot.pollAndPublish(true);

      await deps.outbound.publishCommandResponse({
        action: command.action,
        result: 'success',
        transaction_id: command.transaction_id,
        message: 'Compartment opened.',
      });
    },
  };
}
