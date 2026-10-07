import { execa, ExecaError } from 'execa';
import { randomUUID } from 'node:crypto';
import { writeFile, rename, rm } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

// Resolve from the module, so invoking pnpm or Just from another directory works.
export const repoRoot = fileURLToPath(new URL('../../', import.meta.url));

export interface RunOptions {
  /** Capture trimmed stdout for inspection; otherwise inherit the terminal. */
  capture?: boolean;
  /** Variables scoped to this subprocess, layered over the runtime environment. */
  env?: NodeJS.ProcessEnv;
}

/** The workflow boundary: tests can supply commands without starting real services. */
export interface Runtime {
  root: string;
  env: NodeJS.ProcessEnv;
  dryRun?: boolean;
  log: (line: string) => void;
  run: (program: string, args: string[], options?: RunOptions) => Promise<string>;
}

type RuntimeOptions = Partial<Pick<Runtime, 'root' | 'env' | 'dryRun' | 'log'>>;

/** Build a process runner; dry runs describe commands without executing them. */
export function createRuntime({ root = repoRoot, env = process.env, dryRun = false, log = console.log }: RuntimeOptions = {}): Runtime {
  return {
    root, env, dryRun, log,
    async run(program, args, { capture = false, env: overrides = {} } = {}) {
      if (dryRun) {
        for (const [name, value] of Object.entries(overrides)) log(`Set ${name}=${value} for this command`);
        log([program, ...args].map((arg) => JSON.stringify(arg)).join(' '));
        return '';
      }
      // Argument arrays avoid shell interpolation. The supplied environment is
      // authoritative, including in tests; command-local overrides never leak.
      const result = await execa(program, args, {
        cwd: root,
        env: { ...env, ...overrides },
        extendEnv: false,
        shell: false,
        stdio: capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
      });
      return capture ? (result.stdout ?? '').trim() : '';
    },
  };
}

/** Start only the invoked entry point; importing a workflow has no CLI effects. */
export function runScript<Action extends string = never>(
  moduleUrl: string,
  command: string,
  workflow: (runtime: Runtime, action: Action | undefined) => Promise<void>,
  actions: readonly Action[] = [],
): void {
  if (!process.argv[1] || moduleUrl !== pathToFileURL(process.argv[1]).href) return;
  const args = process.argv.slice(2);
  const positional = args.filter((arg) => arg !== '--dry-run');
  const candidate = positional[0] ?? actions[0];
  const usage = `pnpm --dir scripts ${command} ${actions.length ? `[${actions.join('|')}] ` : ''}[--dry-run]`;
  if (positional.length === 1 && candidate && ['--help', '-h'].includes(candidate)) {
    console.log(`Usage: ${usage}`);
    return;
  }
  if (positional.length > (actions.length ? 1 : 0) || (actions.length && !actions.some((action) => action === candidate))) {
    console.error(`Usage: ${usage}`);
    process.exitCode = 1;
    return;
  }
  // Validation above narrows CLI text to the caller's supported action names.
  const action = candidate as Action | undefined;
  Promise.resolve().then(() => workflow(createRuntime({ dryRun: args.includes('--dry-run') }), action)).catch((error: unknown) => {
    console.error(`Error: ${error instanceof Error ? error.message : String(error)}`);
    // Keep the child's exit status, but allow pending I/O and cleanup to finish.
    process.exitCode = error instanceof ExecaError ? error.exitCode ?? 1 : 1;
  });
}

/** Replace a file only after its complete contents have been written beside it. */
export async function atomicWrite(target: string, content: string): Promise<void> {
  // A sibling temporary file keeps the rename on one filesystem. A failed write
  // leaves the existing config intact; finally cleans up any unfinished temp file.
  const temporary = `${target}.tmp.${randomUUID()}`;
  try {
    await writeFile(temporary, content, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
    await rename(temporary, target);
  } finally {
    await rm(temporary, { force: true });
  }
}

/** Treat HTTP errors and connection failures as not ready, with a bounded probe. */
export async function probeHttp(url: string, timeout = 2000): Promise<boolean> {
  try {
    const response = await fetch(url, { signal: AbortSignal.timeout(timeout) });
    // Only the status matters; release the unread body and its connection.
    await response.body?.cancel();
    return response.ok;
  } catch {
    return false;
  }
}

/** Poll within one overall deadline, including stalled requests and retry delays. */
export async function waitForHttp(url: string, { timeout = 120000, interval = 2000 } = {}): Promise<void> {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await probeHttp(url, Math.max(1, Math.min(2000, deadline - Date.now())))) return;
    await delay(Math.max(0, Math.min(interval, deadline - Date.now())));
  }
  throw new Error(`Service did not become ready at ${url}`);
}
