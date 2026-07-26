import 'dotenv/config';
import path from 'path';
import readline from 'readline';
import { loadScenario, ScenarioValidationError } from './adapters/simulator/scenario';
import { SimulatorCredentialCache } from './adapters/simulator/simulator-credential-cache';
import {
  createSimulatorApp,
  type SimulatedDevice,
  type SimulatorContext,
} from './bootstrap/createSimulatorApp';
import type { DoorState } from './domain/compartment';
import { CONFIG_DIR } from './infrastructure/paths';
import { logger } from './infrastructure/logging';

/**
 * Fleet simulator entrypoint (ADR-0027).
 *
 * Separate from `main.ts`: production has no branch that can reach this file,
 * and this file imports nothing that changes production behaviour.
 */
const DEFAULT_SCENARIO_FILE = path.join(CONFIG_DIR, 'simulator-scenario.yml');

interface CliOptions {
  scenarioFile: string;
  brokerUrl?: string;
  allowProduction: boolean;
  interactive: boolean;
  logTraffic: boolean;
}

function parseArgs(argv: string[]): CliOptions {
  const options: CliOptions = {
    scenarioFile: process.env.SIMULATOR_SCENARIO_FILE?.trim() || DEFAULT_SCENARIO_FILE,
    brokerUrl: process.env.MQTT_BROKER_URL?.trim() || undefined,
    allowProduction: false,
    interactive: process.stdin.isTTY === true,
    logTraffic: true,
  };

  for (let index = 0; index < argv.length; index++) {
    const arg = argv[index];

    switch (arg) {
      case '--scenario':
        options.scenarioFile = requireValue(argv, ++index, '--scenario');
        break;
      case '--broker':
        options.brokerUrl = requireValue(argv, ++index, '--broker');
        break;
      case '--allow-production':
        options.allowProduction = true;
        break;
      case '--no-interactive':
        options.interactive = false;
        break;
      case '--quiet':
        options.logTraffic = false;
        break;
      case '--help':
      case '-h':
        printUsage();
        process.exit(0);
        break;
      default:
        throw new Error(`Unknown argument: ${arg}`);
    }
  }

  return options;
}

function requireValue(argv: string[], index: number, flag: string): string {
  const value = argv[index];

  if (value === undefined || value.startsWith('--')) {
    throw new Error(`${flag} requires a value`);
  }

  return value;
}

function printUsage(): void {
  process.stdout.write(
    [
      'Open-Locker fleet simulator',
      '',
      'Usage: pnpm sim [options]',
      '',
      'Options:',
      '  --scenario <path>     Scenario YAML file (default: $CONFIG_DIR/simulator-scenario.yml)',
      '  --broker <url>        MQTT broker URL (overrides the scenario file)',
      '  --allow-production    Permit running with NODE_ENV/APP_ENV=production',
      '  --no-interactive      Do not read door commands from stdin',
      '  --quiet               Do not echo MQTT traffic to the console',
      '  -h, --help            Show this help',
      '',
      'Interactive commands:',
      '  list                        Show every simulated bank and door state',
      '  open <bank> <compartment>   Mark a door open',
      '  close <bank> <compartment>  Mark a door closed',
      '  unknown <bank> <compartment>  Mark a door state unknown',
      '  jam <bank> <compartment>    Relay fires but the door stays shut',
      '  unjam <bank> <compartment>  Let the door open normally again',
      '  quit                        Shut down',
      '',
    ].join('\n'),
  );
}

/**
 * The simulator publishes fake state under a real locker UUID. Doing that
 * against production would corrupt live read models, so refuse by default
 * (ADR-0027, Decision 1).
 */
function assertNotProduction(allowProduction: boolean): void {
  const environment = (process.env.APP_ENV ?? process.env.NODE_ENV ?? '').trim().toLowerCase();

  if (environment !== 'production' && environment !== 'prod') {
    return;
  }

  if (allowProduction) {
    logger.warn('Simulator starting against a production environment by explicit override', {
      environment,
    });
    return;
  }

  throw new Error(
    `Refusing to start the simulator with APP_ENV/NODE_ENV="${environment}". ` +
      'Pass --allow-production if this is genuinely intended.',
  );
}

async function main(): Promise<void> {
  const options = parseArgs(process.argv.slice(2));

  assertNotProduction(options.allowProduction);

  const scenario = loadScenario(options.scenarioFile);
  const simulator = await createSimulatorApp({
    scenario,
    brokerUrl: options.brokerUrl,
    credentialCache: SimulatorCredentialCache.forScenario(options.scenarioFile),
    logTraffic: options.logTraffic,
  });

  let shuttingDown = false;
  const shutdown = async (reason: string) => {
    if (shuttingDown) {
      return;
    }
    shuttingDown = true;

    logger.info(`Received ${reason}, shutting down simulator...`);
    await simulator.shutdown();
    process.exit(0);
  };

  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));

  if (options.interactive) {
    startInteractiveConsole(simulator, shutdown);
  }
}

/**
 * Console verb → contract door state. The verb is `close` but the contract value
 * is `closed`; publishing the verb produces a payload the backend rejects, so the
 * mapping is explicit rather than a cast.
 */
const DOOR_STATE_BY_COMMAND: Record<string, DoorState> = {
  open: 'open',
  close: 'closed',
  closed: 'closed',
  unknown: 'unknown',
};

function write(message: string): void {
  process.stdout.write(`${message}\n`);
}

function startInteractiveConsole(
  simulator: SimulatorContext,
  shutdown: (reason: string) => Promise<void>,
): void {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

  write('Simulator ready. Type "help" for commands.');

  rl.on('line', (line) => {
    void handleLine(line);
  });

  async function handleLine(line: string): Promise<void> {
    const [command, ...rest] = line.trim().split(/\s+/);

    if (!command) {
      return;
    }

    switch (command.toLowerCase()) {
      case 'help':
        printUsage();
        return;

      case 'list':
        for (const device of simulator.devices) {
          write(`${device.name}  (${device.lockerUuid})`);
          for (const compartmentNumber of device.compartmentNumbers) {
            const jammed = device.isJammed(compartmentNumber) ? '  [jammed]' : '';
            write(`    #${compartmentNumber}  ${device.getDoorState(compartmentNumber)}${jammed}`);
          }
        }
        return;

      case 'open':
      case 'close':
      case 'closed':
      case 'unknown':
        await applyDoorCommand(DOOR_STATE_BY_COMMAND[command.toLowerCase()]!, rest);
        return;

      case 'jam':
      case 'unjam':
        applyJamCommand(command.toLowerCase() === 'jam', rest);
        return;

      case 'quit':
      case 'exit':
        rl.close();
        await shutdown('quit');
        return;

      default:
        write(`Unknown command: ${command}. Type "help" for commands.`);
    }
  }

  /**
   * Bank names routinely contain spaces ("Simulator Bank"), so the last argument
   * is the compartment and everything before it is the name.
   */
  function resolveTargetArgs(
    verb: string,
    args: string[],
  ): { device: SimulatedDevice; compartmentNumber: number } | null {
    const rawCompartment = args.at(-1);
    const bankName = args.slice(0, -1).join(' ');

    if (!bankName || !rawCompartment) {
      write(`Usage: ${verb} <bank> <compartment>`);
      return null;
    }

    const device = simulator.findDevice(bankName);
    if (!device) {
      write(`No simulated bank named "${bankName}". Try "list".`);
      return null;
    }

    const compartmentNumber = Number.parseInt(rawCompartment, 10);
    if (!Number.isInteger(compartmentNumber)) {
      write(`"${rawCompartment}" is not a compartment number.`);
      return null;
    }

    return { device, compartmentNumber };
  }

  async function applyDoorCommand(state: DoorState, args: string[]): Promise<void> {
    const resolved = resolveTargetArgs(state, args);
    if (!resolved) {
      return;
    }

    try {
      await resolved.device.setDoorState(resolved.compartmentNumber, state);
      write(`${resolved.device.name} #${resolved.compartmentNumber} -> ${state}`);
    } catch (error) {
      write(error instanceof Error ? error.message : String(error));
    }
  }

  function applyJamCommand(jammed: boolean, args: string[]): void {
    const resolved = resolveTargetArgs(jammed ? 'jam' : 'unjam', args);
    if (!resolved) {
      return;
    }

    try {
      resolved.device.setJammed(resolved.compartmentNumber, jammed);
      write(
        `${resolved.device.name} #${resolved.compartmentNumber} -> ${
          jammed ? 'jammed (relay fires, door stays shut)' : 'not jammed'
        }`,
      );
    } catch (error) {
      write(error instanceof Error ? error.message : String(error));
    }
  }
}

main().catch((error) => {
  if (error instanceof ScenarioValidationError) {
    logger.error(error.message, { issues: error.issues });
    process.exit(1);
  }

  logger.error('Fatal simulator startup error', error);
  process.exit(1);
});
