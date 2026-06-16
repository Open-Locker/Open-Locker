import type { CommandContext, InboundCommandHandler } from "../command-dispatcher";
import { applyConfigCommandSchema } from "../../../domain/mqtt-schemas";
import type { ApplyConfigUseCase } from "../../../application/apply-config";
import type { OutboundMqttPort } from "../../../ports/mqtt.port";

export function createApplyConfigHandler(deps: {
  applyConfig: ApplyConfigUseCase;
  outbound: OutboundMqttPort;
}): InboundCommandHandler<unknown> {
  return {
    action: "apply_config",
    schema: applyConfigCommandSchema,
    requiresTransactionId: () => true,
    async handle(_ctx: CommandContext, payload: unknown) {
      const command = applyConfigCommandSchema.parse(payload);
      const result = await deps.applyConfig.execute(command);

      await deps.outbound.publishCommandResponse({
        type: "command_response",
        action: command.action,
        result: "success",
        transaction_id: command.transaction_id,
        applied_config_hash: result.appliedConfigHash,
        message: result.message,
      });
    },
  };
}
