import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/filament/provisioning-token-copy.js', import.meta.url), 'utf8');

function createComponent({ writeText = async () => {}, messages = {} } = {}) {
    const timers = new Map();
    const notifications = [];
    let nextTimerId = 0;

    class MockNotification {
        title(value) {
            this.notificationTitle = value;
            return this;
        }

        body(value) {
            this.notificationBody = value;
            return this;
        }

        danger() {
            return this;
        }

        send() {
            notifications.push({ title: this.notificationTitle, body: this.notificationBody });
        }
    }

    const window = { FilamentNotification: MockNotification };
    const context = {
        window,
        navigator: { clipboard: { writeText } },
        setTimeout(callback, delay) {
            const id = ++nextTimerId;
            timers.set(id, { callback, delay });
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
    };

    vm.runInNewContext(source, context);

    return {
        component: window.provisioningTokenCopy('one-time-token', {
            errorTitle: 'Copy failed',
            errorBody: 'Use HTTPS or localhost and copy manually.',
            copyLabel: 'Copy token',
            copiedLabel: 'Copied',
            ...messages,
        }),
        notifications,
        timers,
    };
}

test('copies the exact token and resets the copied state after the feedback delay', async () => {
    let copiedToken;
    const { component, timers } = createComponent({
        writeText: async (value) => {
            copiedToken = value;
        },
    });

    await component.copyToken();

    assert.equal(copiedToken, 'one-time-token');
    assert.equal(component.copyLabel, 'Copy token');
    assert.equal(component.copiedLabel, 'Copied');
    assert.equal(component.copied, true);
    assert.equal(timers.size, 1);
    const [timer] = timers.values();
    assert.equal(timer.delay, 1500);
    timer.callback();
    assert.equal(component.copied, false);
});

test('notifies the user when clipboard access is rejected and keeps copy state false', async () => {
    const { component, notifications, timers } = createComponent({
        writeText: async () => {
            throw new Error('Clipboard write blocked');
        },
    });

    await component.copyToken();

    assert.equal(component.copied, false);
    assert.equal(timers.size, 0);
    assert.deepEqual(notifications, [{
        title: 'Copy failed',
        body: 'Use HTTPS or localhost and copy manually.',
    }]);
});

test('clears the copied-state timer when the Alpine component is destroyed', async () => {
    const { component, timers } = createComponent();

    await component.copyToken();
    assert.equal(timers.size, 1);

    component.destroy();

    assert.equal(timers.size, 0);
});
