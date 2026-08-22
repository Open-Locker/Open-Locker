import type { CommandContext, InboundCommandHandler } from '../command-dispatcher';
import { applyConfigCommandSchema, type ApplyConfigCommand } from '../../../domain/mqtt-schemas';
import type { ApplyConfigUseCase } from '../../../application/apply-config';

export function createApplyConfigHandler(deps: {
  applyConfig: ApplyConfigUseCase;
}): InboundCommandHandler<ApplyConfigCommand> {
  return {
    action: 'apply_config',
    schema: applyConfigCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, command: ApplyConfigCommand) {
      const result = await deps.applyConfig.execute(command);

      return {
        action: command.action,
        result: 'success',
        transaction_id: command.transaction_id,
        applied_config_hash: result.appliedConfigHash,
        message: result.message,
      };
    },
  };
}
