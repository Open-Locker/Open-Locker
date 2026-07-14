import type { CommandContext, InboundCommandHandler } from '../command-dispatcher';
import { applyConfigCommandSchema, type ApplyConfigCommand } from '../../../domain/mqtt-schemas';
import type { ApplyConfigUseCase } from '../../../application/apply-config';
import type { OutboundMqttPort } from '../../../ports/mqtt.port';

export function createApplyConfigHandler(deps: {
  applyConfig: ApplyConfigUseCase;
  outbound: OutboundMqttPort;
}): InboundCommandHandler<ApplyConfigCommand> {
  return {
    action: 'apply_config',
    schema: applyConfigCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, command: ApplyConfigCommand) {
      const result = await deps.applyConfig.execute(command);

      await deps.outbound.publishCommandResponse({
        action: command.action,
        result: 'success',
        transaction_id: command.transaction_id,
        applied_config_hash: result.appliedConfigHash,
        message: result.message,
      });
    },
  };
}
