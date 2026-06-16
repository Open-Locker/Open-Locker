import type { InboundCommandHandler } from "../command-dispatcher";
import type { ApplyConfigUseCase } from "../../../application/apply-config";
import type { OutboundMqttPort } from "../../../ports/mqtt.port";
export declare function createApplyConfigHandler(deps: {
    applyConfig: ApplyConfigUseCase;
    outbound: OutboundMqttPort;
}): InboundCommandHandler<unknown>;
