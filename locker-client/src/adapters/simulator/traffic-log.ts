import type { OutboundPublishOptions } from '../../ports/mqtt.port';

/**
 * Human-readable MQTT traffic log for the simulator.
 *
 * The real client is deliberately quiet — it runs unattended on a Pi and logs
 * warnings, not every message. A simulator is the opposite: watching the traffic
 * *is* the reason to run it, and a developer debugging a backend workflow should
 * not have to attach an MQTT client to see what the fake locker said.
 *
 * This lives in simulator code and wraps the publish/dispatch seams from the
 * outside, so no production logging behaviour changes.
 */
export interface TrafficLogger {
  outbound(topic: string, payload: string, options?: OutboundPublishOptions): void;
  inbound(topic: string, rawMessage: string): void;
}

export const silentTrafficLogger: TrafficLogger = {
  outbound() {},
  inbound() {},
};

/** Strips the locker UUID so lines stay readable: `…/state/heartbeat`. */
function shortTopic(topic: string): string {
  return topic.replace(/^locker\/[^/]+\//, '');
}

function summarize(payload: unknown): string {
  if (typeof payload !== 'object' || payload === null) {
    return '';
  }

  const body = payload as Record<string, unknown>;

  if (Array.isArray(body.compartments)) {
    const doors = body.compartments
      .map((entry) => {
        const compartment = entry as { compartment_number?: number; door_state?: string };

        return `#${compartment.compartment_number}:${compartment.door_state}`;
      })
      .join(' ');

    return doors;
  }

  if (typeof body.uptime_seconds === 'number') {
    return `uptime=${body.uptime_seconds}s`;
  }

  if (typeof body.action === 'string') {
    const parts = [body.action, String(body.result ?? '')];

    if (typeof body.error_code === 'string') {
      parts.push(body.error_code);
    }
    if (typeof body.transaction_id === 'string') {
      parts.push(`tx=${body.transaction_id.slice(0, 8)}`);
    }

    return parts.filter(Boolean).join(' ');
  }

  return '';
}

export function createConsoleTrafficLogger(
  bankName: string,
  write: (line: string) => void = (line) => process.stdout.write(`${line}\n`),
): TrafficLogger {
  const prefix = `[${bankName}]`;

  return {
    outbound(topic, payload, options) {
      let parsed: unknown;
      try {
        parsed = JSON.parse(payload);
      } catch {
        parsed = null;
      }

      const retained = options?.retain ? ' (retained)' : '';
      const summary = summarize(parsed);

      write(`${prefix} → ${shortTopic(topic)}${retained}${summary ? `  ${summary}` : ''}`);
    },

    inbound(topic, rawMessage) {
      let parsed: unknown;
      try {
        parsed = JSON.parse(rawMessage);
      } catch {
        write(`${prefix} ← ${shortTopic(topic)}  <unparseable>`);
        return;
      }

      const body = parsed as Record<string, unknown>;
      const action = typeof body.action === 'string' ? body.action : '?';
      const transactionId =
        typeof body.transaction_id === 'string' ? ` tx=${body.transaction_id.slice(0, 8)}` : '';
      const compartment = (body.data as { compartment_number?: number } | undefined)
        ?.compartment_number;
      const target = compartment === undefined ? '' : ` #${compartment}`;

      write(`${prefix} ← ${shortTopic(topic)}  ${action}${target}${transactionId}`);
    },
  };
}
