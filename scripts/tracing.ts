import { stat, mkdir } from 'node:fs/promises';
import { homedir } from 'node:os';
import { join, dirname, resolve } from 'node:path';
import { ExecaError } from 'execa';
import { atomicWrite, probeHttp, waitForHttp, runScript, type Runtime } from './lib/runtime.ts';

const actions = ['status', 'overlay', 'up', 'down'] as const;
export type TracingAction = typeof actions[number];

const backend = ['compose', '-f', 'locker-backend/docker-compose.yml'];

async function exists(path: string): Promise<boolean> {
  try { await stat(path); return true; }
  catch (error) {
    if (error instanceof Error && 'code' in error && error.code === 'ENOENT') return false;
    throw error;
  }
}

/** Orchestrate the backend overlay and a separate, persistent SigNoz project. */
export async function tracing(action: TracingAction, runtime: Runtime): Promise<void> {
  // Resolve relative overrides from the repository, never the invoking shell.
  const signozHome = resolve(runtime.root, runtime.env.SIGNOZ_DIR || join(homedir(), '.open-locker/signoz'));
  const uiPort = runtime.env.SIGNOZ_UI_PORT || '8085';
  const httpPort = runtime.env.FORWARD_OTLP_HTTP_PORT || '4418';
  const grpcPort = runtime.env.FORWARD_OTLP_GRPC_PORT || '4417';
  for (const port of [uiPort, httpPort, grpcPort]) {
    if (!/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535) throw new Error(`Invalid port: ${port}`);
  }
  const url = `http://localhost:${uiPort}`;
  const deploy = join(signozHome, 'deploy/docker');
  const composeFile = join(deploy, 'docker-compose.yaml');
  const override = join(deploy, 'signoz-port-override.yml');
  // SigNoz uses its own Compose project so backend changes cannot remove its data.
  const signozCompose = async (...args: string[]) => {
    if (!runtime.dryRun && !await exists(composeFile)) throw new Error(`SigNoz Compose file not found: ${composeFile}`);
    const files = ['compose', '-p', 'signoz', '-f', composeFile];
    if (runtime.dryRun || await exists(override)) files.push('-f', override);
    return runtime.run('docker', [...files, ...args]);
  };
  const overlay = () => runtime.run('docker', [
    ...backend, '-f', 'locker-backend/docker-compose.observability.yml', 'up', '-d',
  ], { env: { FORWARD_OTLP_HTTP_PORT: httpPort, FORWARD_OTLP_GRPC_PORT: grpcPort } });
  const status = async () => {
    if (runtime.dryRun) {
      runtime.log(`Probe ${url} and inspect the collector and backend instrumentation`);
      return;
    }
    // Query our collector by Compose service; SigNoz also runs a collector.
    // Docker failures propagate; stopped services still produce a useful status.
    const collector = await runtime.run('docker', [
      ...backend, '-f', 'locker-backend/docker-compose.observability.yml', 'ps', '--status', 'running', '-q', 'otel-collector',
    ], { capture: true });
    let instrumented = '';
    try {
      instrumented = await runtime.run('docker', [...backend, 'exec', '-T', 'app', 'printenv', 'OTEL_EXPORTER_OTLP_ENDPOINT'], { capture: true });
    } catch (error) {
      // A stopped app or missing variable is reported as uninstrumented. Launch
      // failures and interrupted processes still abort the command.
      if (!(error instanceof ExecaError) || !error.exitCode) throw error;
    }
    runtime.log(`SigNoz UI              ${await probeHttp(url) ? url : 'DOWN'}`);
    runtime.log(`Our collector          ${collector ? `up (host ports ${grpcPort}/${httpPort})` : 'DOWN'}`);
    runtime.log(`Backend instrumented   ${instrumented ? 'yes' : 'NO'}`);
    runtime.log('Start tracing with: just trace-up');
    runtime.log(`Simulator OTLP endpoint: http://localhost:${httpPort}`);
  };

  switch (action) {
    case 'overlay':
      await overlay();
      break;
    case 'up':
      // Never clone into or overwrite an unrelated directory. Dry runs preview
      // all potential steps without inspecting the filesystem.
      if (runtime.dryRun || !await exists(join(signozHome, '.git'))) {
        if (!runtime.dryRun && await exists(signozHome)) throw new Error(`SIGNOZ_DIR exists but is not a SigNoz Git checkout: ${signozHome}`);
        if (!runtime.dryRun) await mkdir(dirname(signozHome), { recursive: true });
        await runtime.run('git', ['clone', '-b', 'v0.99.0', '--depth', '1', 'https://github.com/SigNoz/signoz.git', signozHome]);
      }
      // Compose !override replaces the upstream port list instead of appending
      // a second UI binding. Keep this host setting outside the cloned source.
      if (runtime.dryRun) runtime.log(`Write ${override} with UI port ${uiPort}`);
      else await atomicWrite(override, `services:\n    signoz:\n        ports: !override\n            - "${uiPort}:8080"\n`);
      await signozCompose('up', '-d', '--remove-orphans');
      await overlay();
      if (runtime.dryRun) runtime.log(`Wait up to 120 seconds for ${url}`);
      else {
        runtime.log(`Waiting for SigNoz at ${url}...`);
        await waitForHttp(url);
      }
      await status();
      break;
    case 'down':
      // Restore the backend first, then stop SigNoz without deleting its volumes.
      await runtime.run('docker', [...backend, 'up', '-d', '--remove-orphans']);
      if (runtime.dryRun || await exists(join(signozHome, '.git'))) await signozCompose('stop');
      else runtime.log('SigNoz checkout not found; nothing to stop.');
      runtime.log('Tracing off. SigNoz data is kept.');
      break;
    case 'status':
      await status();
      break;
    default:
      throw new Error(`Unknown tracing action: ${action}`);
  }
}

runScript(import.meta.url, 'trace', (runtime, action) => tracing(action ?? 'status', runtime), actions);
