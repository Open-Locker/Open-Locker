import type { InboundCommandHandler } from '../command-dispatcher';
import type { OpenCompartmentUseCase } from '../../../application/open-compartment';
import type { OutboundMqttPort } from '../../../ports/mqtt.port';
import type { PollCompartmentStateUseCase } from '../../../application/state-publishing';
export declare function createOpenCompartmentHandler(deps: {
    openCompartment: OpenCompartmentUseCase;
    outbound: OutboundMqttPort;
    pollSnapshot: PollCompartmentStateUseCase;
}): InboundCommandHandler<unknown>;
