/**
 * Signing this device up for notices when the system is closed.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/webPush.js'), 'utf8');
const lib = new Function(src.replace(/export function/g, 'function')
  + '\nreturn { keyBytes, pushSupported, subscribeDevice, unsubscribeDevice };')();

const KEY = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

function fakeBrowser(existing = null) {
  const calls = [];
  const sub = {
    endpoint: 'https://fcm.googleapis.com/fcm/send/device-1',
    toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/device-1', keys: { p256dh: 'p', auth: 'a' } }),
    unsubscribe: () => { calls.push('browser-unsubscribe'); return Promise.resolve(true); },
  };
  const nav = {
    serviceWorker: {
      register: () => Promise.resolve(),
      ready: Promise.resolve({
        pushManager: {
          getSubscription: () => Promise.resolve(existing ? sub : null),
          subscribe: opts => { calls.push(['subscribe', opts.userVisibleOnly, opts.applicationServerKey.length]); return Promise.resolve(sub); },
        },
      }),
    },
  };
  const win = { PushManager: function () {}, Notification: { permission: 'granted' }, isSecureContext: true };
  const http = {
    get: url => { calls.push(['get', url]); return Promise.resolve({ data: { key: KEY } }); },
    post: (url, body) => { calls.push(['post', url, body]); return Promise.resolve({ data: {} }); },
  };
  return { nav, win, http, calls };
}

test('the server key becomes the 65 bytes the browser wants', () => {
  const bytes = lib.keyBytes(KEY);
  assert.strictEqual(bytes.length, 65);
  assert.strictEqual(bytes[0], 4);
});

test('push needs a secure page and a browser that has it', () => {
  const b = fakeBrowser();
  assert.strictEqual(lib.pushSupported(b.win, b.nav), true);
  assert.strictEqual(lib.pushSupported({ ...b.win, isSecureContext: false }, b.nav), false, 'a phone on http://192.168.x.x');
  assert.strictEqual(lib.pushSupported({ isSecureContext: true }, b.nav), false);
});

test('a new device subscribes with the server key and tells the server', async () => {
  const b = fakeBrowser();
  assert.strictEqual(await lib.subscribeDevice(b.http, b.win, b.nav), true);

  assert.deepStrictEqual(b.calls[0], ['get', 'push/key']);
  assert.deepStrictEqual(b.calls[1], ['subscribe', true, 65]);
  assert.deepStrictEqual(b.calls[2], ['post', 'push/subscribe',
    { endpoint: 'https://fcm.googleapis.com/fcm/send/device-1', keys: { p256dh: 'p', auth: 'a' } }]);
});

test('a device already subscribed is just re-sent, for whoever is signed in now', async () => {
  const b = fakeBrowser(true);
  await lib.subscribeDevice(b.http, b.win, b.nav);

  assert.ok(!b.calls.some(c => c[0] === 'subscribe'));
  assert.strictEqual(b.calls[b.calls.length - 1][1], 'push/subscribe');
});

test('nothing happens until the person has allowed notifications', async () => {
  const b = fakeBrowser();
  assert.strictEqual(await lib.subscribeDevice(b.http, { ...b.win, Notification: { permission: 'default' } }, b.nav), false);
  assert.deepStrictEqual(b.calls, []);
});

test('signing out stops notices to this device', async () => {
  const b = fakeBrowser(true);
  await lib.unsubscribeDevice(b.http, b.win, b.nav);

  assert.deepStrictEqual(b.calls[0], ['post', 'push/unsubscribe', { endpoint: 'https://fcm.googleapis.com/fcm/send/device-1' }]);
  assert.ok(b.calls.includes('browser-unsubscribe'));
});
