import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import test from 'node:test';
import mqtt from 'mqtt';

/**
 * Proves the assumption per-provisioning identities rest on: once an identity is
 * disabled, a session that is *already connected* stops being authorised — even
 * though a new identity now exists for the same locker bank.
 *
 * If that were false, revocation would only take effect on reconnect and evicting
 * the old session at the broker would be required for correctness. It is asserted
 * here rather than reasoned about because it is a property of the broker and its
 * auth plugin, not of our code, and it can change when either is upgraded. That is
 * also why the broker image is pinned.
 *
 * Opt-in: needs the backend stack running, so it is skipped unless
 * OPEN_LOCKER_BROKER_TEST=1. Unlike the rest of the suite it writes to the
 * development database, through artisan in the app container.
 */
const enabled = process.env.OPEN_LOCKER_BROKER_TEST === '1';
const brokerUrl = process.env.OPEN_LOCKER_BROKER_URL ?? 'mqtt://localhost:1883';
const backendDir =
  process.env.OPEN_LOCKER_BACKEND_DIR ?? '/opt/homebrew/var/www/open-locker/locker-backend';

function artisan(php: string): string {
  return execFileSync(
    'docker',
    [
      'compose',
      'exec',
      '-T',
      '-u',
      'www-data',
      'app',
      'php',
      'artisan',
      'tinker',
      '--execute',
      php,
    ],
    { cwd: backendDir, encoding: 'utf8' },
  );
}

/** Publishes once and resolves with whether the broker accepted it. */
function publishAccepted(client: mqtt.MqttClient, topic: string): Promise<boolean> {
  return new Promise((resolve) => {
    client.publish(topic, JSON.stringify({ probe: true }), { qos: 1 }, (error) =>
      resolve(error === undefined || error === null),
    );
  });
}

test(
  'a connected session loses authorisation once its identity is revoked',
  { skip: enabled ? false : 'set OPEN_LOCKER_BROKER_TEST=1 with the stack running' },
  async (t) => {
    const marker = `itest-${Date.now()}`;
    const password = 'integration-test-password';

    // Registered before anything is created, and keyed by name rather than by id:
    // a failure partway through setup would otherwise strand rows in a database
    // this test does not own.
    t.after(() => {
      artisan(`
        $bank = \\App\\Models\\LockerBank::where('name', '${marker}')->first();
        if ($bank !== null) {
          \\App\\Models\\MqttUser::where('locker_bank_id', $bank->id)->delete();
          $bank->delete();
        }
        \\App\\Models\\MqttUser::where('username', '${marker}')->delete();
      `);
    });

    // A bank of its own, so nothing here touches identities in use. Created
    // straight through the model rather than through the aggregate: it is a
    // throwaway fixture and deliberately has no event stream, so this is not a
    // pattern to copy into a test that cares about locker-bank behaviour.
    const setup = artisan(`
      $bank = \\App\\Models\\LockerBank::create(['name' => '${marker}']);
      $u = new \\App\\Models\\MqttUser();
      $u->username = '${marker}';
      $u->password_hash = \\Illuminate\\Support\\Facades\\Hash::make('${password}');
      $u->locker_bank_id = $bank->id;
      $u->enabled = true;
      $u->save();
      echo $bank->id;
    `);
    const lockerUuid = setup.trim().split('\n').pop()?.trim() ?? '';
    assert.match(lockerUuid, /^[0-9a-f-]{36}$/, 'setup should return the locker bank uuid');

    const client = mqtt.connect(brokerUrl, {
      username: marker,
      password,
      clientId: `${marker}-client`,
      clean: true,
      reconnectPeriod: 0, // this exact session is the subject of the test
      protocolVersion: 5, // v5 reports authorisation failures as reason codes
    });

    try {
      await new Promise<void>((resolve, reject) => {
        client.once('connect', () => resolve());
        client.once('error', reject);
      });

      const topic = `locker/${lockerUuid}/event`;
      assert.equal(await publishAccepted(client, topic), true, 'a live identity may publish');

      // Exactly what a re-provisioning does: retire the bank's identities, then
      // issue a fresh opaque one. The session under test stays connected.
      artisan(`
        \\App\\Models\\MqttUser::where('locker_bank_id', '${lockerUuid}')
          ->where('enabled', true)
          ->update(['enabled' => false, 'revoked_at' => now()]);
        $n = new \\App\\Models\\MqttUser();
        $n->username = \\Illuminate\\Support\\Str::random(32);
        $n->password_hash = \\Illuminate\\Support\\Facades\\Hash::make('${password}');
        $n->locker_bank_id = '${lockerUuid}';
        $n->enabled = true;
        $n->save();
      `);

      assert.equal(
        await publishAccepted(client, topic),
        false,
        'a revoked identity must be denied on the session it is already holding',
      );
    } finally {
      client.end(true);
    }
  },
);
