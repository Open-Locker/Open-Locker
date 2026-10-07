import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { parse } from 'dotenv';
import { atomicWrite, runScript, type Runtime } from './lib/runtime.ts';

/** Resolve a password without loading backend variables into the host environment. */
export function mqttPassword(envText: string, environment: NodeJS.ProcessEnv = {}): string {
  // dotenv parses file quoting; actual environment values are already literal.
  const password = environment.MOSQ_HTTP_PASS || parse(envText).MOSQ_HTTP_PASS || '';
  if (!password) throw new Error('MOSQ_HTTP_PASS is missing from the environment and backend .env');
  if (/[\r\n\0]/.test(password)) throw new Error('MOSQ_HTTP_PASS must be a single line');
  return password;
}

runScript(import.meta.url, 'setup-mqtt', setupMqtt);

/** Generate the host-mounted MQTT config, then restart MQTT only after writing it. */
export async function setupMqtt(runtime: Runtime): Promise<void> {
  const target = join(runtime.root, 'locker-backend/mosquitto/mosquitto.conf');
  if (runtime.dryRun) {
    runtime.log(`Generate ${target} from its template and MOSQ_HTTP_PASS (value hidden)`);
  } else {
    const envText = await readFile(join(runtime.root, 'locker-backend/.env'), 'utf8');
    const template = await readFile(`${target}.template`, 'utf8');
    const password = mqttPassword(envText, runtime.env);
    // A callback keeps dollar signs in passwords literal rather than expanding
    // JavaScript replacement patterns such as $&. Never include secrets in logs.
    await atomicWrite(target, template.replaceAll('__AUTH_PASS__', () => password));
    runtime.log(`Mosquitto configuration generated at ${target}`);
  }
  await runtime.run('docker', ['compose', '-f', 'locker-backend/docker-compose.yml', 'restart', 'mqtt']);
}
