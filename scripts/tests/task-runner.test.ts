import test from 'node:test';
import type { TestContext } from 'node:test';
import assert from 'node:assert/strict';
import { cp, mkdtemp, mkdir, readFile, readdir, realpath, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { spawnSync } from 'node:child_process';
import type { AddressInfo } from 'node:net';
import { ExecaError } from 'execa';

import { createRuntime, repoRoot, waitForHttp, type Runtime, type RunOptions } from '../lib/runtime.ts';
import { mqttPassword, setupMqtt } from '../setup-mqtt.ts';
import { tracing } from '../tracing.ts';

// Resolve the loader before changing cwd; child processes must find tsx even
// when launched from outside the package or a temporary repository copy.
const tsxLoader = import.meta.resolve('tsx');

async function fixture(t: TestContext): Promise<string> {
  const root = await realpath(await mkdtemp(join(tmpdir(), 'open locker scripts ')));
  t.after(() => rm(root, { recursive: true, force: true }));
  return root;
}

test('all workflows preview without files, services or subprocesses', async () => {
  const output: string[] = [];
  const runtime = createRuntime({ root: join(tmpdir(), 'absent repository'), env: {}, dryRun: true, log: (line) => output.push(line) });
  await setupMqtt(runtime);
  for (const action of ['status', 'overlay', 'up', 'down'] as const) await tracing(action, runtime);
  assert.ok(output.some((line) => line.includes('clone')));
  assert.ok(output.every((line) => !line.includes('MOSQ_HTTP_PASS=')));
});

test('script entry points work outside repo and reject invalid arguments', () => {
  const tracingScript = join(repoRoot, 'scripts/tracing.ts');
  const result = spawnSync(process.execPath, ['--import', tsxLoader, tracingScript, 'overlay', '--dry-run'], { cwd: tmpdir(), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /docker-compose.observability.yml/);
  const invalidCommands: [string, string[]][] = [[tracingScript, ['unknown']], [tracingScript, ['up', 'extra']], [join(repoRoot, 'scripts/setup-mqtt.ts'), ['extra']]];
  for (const [script, args] of invalidCommands) {
    const invalid = spawnSync(process.execPath, ['--import', tsxLoader, script, ...args], { encoding: 'utf8' });
    assert.equal(invalid.status, 1);
    assert.match(invalid.stderr, /Usage:/);
  }
});

test('Just previews every public recipe and propagates Node failure in a path with spaces', async (t) => {
  const recipes = ['script-deps', 'install-hooks', 'sim', 'start', 'status', 'stop', 'setup-mqtt', 'trace-overlay', 'trace-up', 'trace-down', 'trace-status'];
  for (const recipe of recipes) {
    const result = spawnSync('just', ['--justfile', join(repoRoot, 'Justfile'), '--dry-run', recipe], { cwd: tmpdir(), encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    if (recipe.startsWith('trace-') || recipe === 'setup-mqtt') assert.match(result.stderr, /pnpm --dir scripts (trace|setup-mqtt)/);
  }
  const root = await fixture(t);
  await writeFile(join(root, 'Justfile'), await readFile(join(repoRoot, 'Justfile')));
  await cp(join(repoRoot, 'scripts'), join(root, 'scripts'), {
    recursive: true, filter: (source) => basename(source) !== 'node_modules',
  });
  // Share installed dependencies without copying pnpm's platform-specific links.
  await symlink(join(repoRoot, 'scripts/node_modules'), join(root, 'scripts/node_modules'), 'junction');
  const invalid = spawnSync('just', ['--justfile', join(root, 'Justfile'), 'trace-overlay'], {
    cwd: tmpdir(), env: { ...process.env, SIGNOZ_UI_PORT: 'invalid' }, encoding: 'utf8',
  });
  assert.notEqual(invalid.status, 0);
  assert.match(invalid.stderr, /Invalid port/);
});
test('process arguments with spaces and shell characters stay literal; failures propagate', async (t) => {
  const root = await fixture(t);
  const runtime = createRuntime({ root });
  const value = 'a path with spaces & $HOME | "quoted"';
  const output = await runtime.run(process.execPath, ['-e', 'console.log(JSON.stringify({arg: process.argv[1], cwd: process.cwd(), env: process.env.SCRIPT_TEST_VALUE}))', value], { capture: true, env: { SCRIPT_TEST_VALUE: 'override' } });
  assert.deepEqual(JSON.parse(output), { arg: value, cwd: root, env: 'override' });
  await assert.rejects(runtime.run(process.execPath, ['-e', 'process.exit(7)'], { capture: true }), { exitCode: 7 });
  await assert.rejects(runtime.run('open-locker-missing-executable', [], { capture: true }), (error) => {
    assert.ok(error instanceof ExecaError);
    assert.equal(error.failed, true);
    assert.match(error.message, /open-locker-missing-executable/);
    assert.ok(error.code === 'ENOENT' || error.exitCode !== undefined);
    return true;
  });
});

test('MQTT parses dotenv quotes, CRLF and special characters; matching environment is allowed', () => {
  const content = 'MOSQ_HTTP_PASS="first & $& \\ value"\r\n';
  assert.equal(mqttPassword(content), 'first & $& \\ value');
  assert.equal(mqttPassword(content, { MOSQ_HTTP_PASS: 'first & $& \\ value' }), 'first & $& \\ value');
  assert.equal(mqttPassword('MOSQ_HTTP_PASS="\'literal quotes\'"', { MOSQ_HTTP_PASS: "'literal quotes'" }), "'literal quotes'");
  assert.equal(mqttPassword('MOSQ_HTTP_PASS=first\nMOSQ_HTTP_PASS=last # comment'), 'last');
  assert.equal(mqttPassword(' export MOSQ_HTTP_PASS = "quoted # value" # comment'), 'quoted # value');
  const environment = {};
  mqttPassword(content, environment);
  assert.deepEqual(environment, {});
  assert.throws(() => mqttPassword('MOSQ_HTTP_PASS=""'), /missing/);
  assert.throws(() => mqttPassword('MOSQ_HTTP_PASS="bad\nsecret"', { MOSQ_HTTP_PASS: 'bad\nsecret' }), /single line/);
  assert.throws(() => mqttPassword('MOSQ_HTTP_PASS="bad\\nsecret"'), /single line/);
});

test('MQTT refuses host secrets that differ from the backend file before changing config', async (t) => {
  const root = await fixture(t);
  const directory = join(root, 'locker-backend/mosquitto');
  await mkdir(directory, { recursive: true });
  await writeFile(join(root, 'locker-backend/.env'), 'MOSQ_HTTP_PASS=file-secret');
  const target = join(directory, 'mosquitto.conf');
  await writeFile(`${target}.template`, 'password=__AUTH_PASS__');
  await writeFile(target, 'old config');
  const runtime: Runtime = { root, env: { MOSQ_HTTP_PASS: 'host-secret' }, log() {}, run: () => assert.fail('Docker called') };
  await assert.rejects(setupMqtt(runtime), /must match/);
  assert.equal(await readFile(target, 'utf8'), 'old config');
  assert.throws(() => mqttPassword('', { MOSQ_HTTP_PASS: 'host-secret' }), /missing/);
});

test('MQTT writes encoded webhook secrets before recreating, cleans temporary files and hides secrets', async (t) => {
  const root = await fixture(t);
  const directory = join(root, 'locker-backend/mosquitto');
  await mkdir(directory, { recursive: true });
  await writeFile(join(root, 'locker-backend/.env'), 'MOSQ_HTTP_PASS="secret $& \\ &#+%=é"\r\n');
  const target = join(directory, 'mosquitto.conf');
  await writeFile(`${target}.template`, 'auth_opt_http_getuser_uri /api/mosq/auth?mosq_secret=__AUTH_PASS__\nauth_opt_http_aclcheck_uri /api/mosq/acl?mosq_secret=__AUTH_PASS__\n');
  await writeFile(target, 'old config');
  const output: string[] = [];
  const runtime: Runtime = {
    root, env: {}, log: (line) => output.push(line),
    async run(program, args) {
      assert.equal(program, 'docker');
      assert.deepEqual(args, ['compose', '-f', 'locker-backend/docker-compose.yml', 'up', '-d', '--no-deps', '--force-recreate', 'mqtt']);
      const config = await readFile(target, 'utf8');
      assert.equal(config, 'auth_opt_http_getuser_uri /api/mosq/auth?mosq_secret=secret%20%24%26%20%5C%20%26%23%2B%25%3D%C3%A9\nauth_opt_http_aclcheck_uri /api/mosq/acl?mosq_secret=secret%20%24%26%20%5C%20%26%23%2B%25%3D%C3%A9\n');
      for (const line of config.trim().split('\n')) {
        const url = new URL(line.split(' ')[1]!, 'http://app:8080');
        assert.equal(url.searchParams.get('mosq_secret'), 'secret $& \\ &#+%=é');
        assert.equal(url.hash, '');
      }
      throw new Error('recreate failed');
    },
  };
  await assert.rejects(setupMqtt(runtime), /recreate failed/);
  assert.ok(output.every((line) => !line.includes('secret')));
  assert.deepEqual((await readdir(directory)).sort(), ['mosquitto.conf', 'mosquitto.conf.template']);
  await writeFile(join(root, 'locker-backend/.env'), 'MOSQ_HTTP_PASS=');
  const previous = await readFile(target, 'utf8');
  await assert.rejects(setupMqtt({ root, env: {}, log() {}, run: () => assert.fail('Docker called') }), /missing/);
  assert.equal(await readFile(target, 'utf8'), previous);
});

test('tracing refuses unrelated existing checkout and invalid ports', async (t) => {
  const root = await fixture(t);
  const signoz = join(root, 'existing');
  await mkdir(signoz);
  const runtime: Runtime = { root, env: { SIGNOZ_DIR: signoz }, log() {}, run: () => assert.fail('command called') };
  await assert.rejects(tracing('up', runtime), /not a SigNoz Git checkout/);
  await assert.rejects(tracing('overlay', { ...runtime, env: { SIGNOZ_UI_PORT: '8085\nextra' } }), /Invalid port/);
});

test('tracing down restores backend first, stops SigNoz and retains data', async (t) => {
  const root = await fixture(t);
  const signoz = join(root, 'SigNoz with spaces');
  await mkdir(join(signoz, '.git'), { recursive: true });
  await mkdir(join(signoz, 'deploy/docker'), { recursive: true });
  await writeFile(join(signoz, 'deploy/docker/docker-compose.yaml'), 'services: {}');
  const calls: string[][] = [];
  await tracing('down', { root, env: { SIGNOZ_DIR: signoz }, log() {}, async run(program, args) { calls.push(args); return ''; } });
  assert.deepEqual(calls[0].slice(-3), ['up', '-d', '--remove-orphans']);
  assert.equal(calls[1].at(-1), 'stop');
  assert.ok(!calls.flat().includes('--volumes'));
  assert.equal(calls[1].filter((arg) => arg === '-f').length, 1);
});

test('HTTP readiness retries and has a bounded deadline', async (t) => {
  let count = 0;
  const server = createServer((request, response) => { response.writeHead(++count > 1 ? 200 : 503); response.end(); });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(() => { server.closeAllConnections(); server.close(); });
  const url = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
  await waitForHttp(url, { timeout: 1000, interval: 1 });
  assert.ok(count >= 2);
  await assert.rejects(waitForHttp(url, { timeout: 0 }), /did not become ready/);
});

test('tracing up writes port override and stops on backend startup failure', async (t) => {
  const root = await fixture(t);
  const signoz = join(root, 'SigNoz with spaces');
  const deploy = join(signoz, 'deploy/docker');
  await mkdir(join(signoz, '.git'), { recursive: true });
  await mkdir(deploy, { recursive: true });
  await writeFile(join(deploy, 'docker-compose.yaml'), 'services: {}');
  const calls: { program: string; args: string[]; options?: RunOptions }[] = [];
  await assert.rejects(tracing('up', {
    root, env: { SIGNOZ_DIR: signoz, SIGNOZ_UI_PORT: '8090' }, log() {},
    async run(program, args, options) {
      calls.push({ program, args, options });
      if (calls.length === 2) throw new Error('backend failed');
      return '';
    },
  }), /backend failed/);
  assert.equal(calls.length, 2);
  assert.equal(calls[0].args[2], 'signoz');
  assert.deepEqual(calls[1].options?.env, { FORWARD_OTLP_HTTP_PORT: '4418', FORWARD_OTLP_GRPC_PORT: '4417' });
  assert.match(await readFile(join(deploy, 'signoz-port-override.yml'), 'utf8'), /"8090:8080"/);
});

test('HTTP readiness cancels a stalled request at its deadline', async (t) => {
  const server = createServer(() => {});
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(() => { server.closeAllConnections(); server.close(); });
  const started = Date.now();
  await assert.rejects(waitForHttp(`http://127.0.0.1:${(server.address() as AddressInfo).port}`, { timeout: 40, interval: 1 }), /did not become ready/);
  assert.ok(Date.now() - started < 2000);
});
