/**
 * The service worker's half of phone / closed-browser notifications.
 *
 * The server pushes a notice; the push service wakes public/pos-sw.js, which
 * shows it. When the system is already open and on screen the bell's own
 * pop-up covers it, so the worker only nudges the page instead of showing a
 * second copy. Tapping the notification opens the page it is about.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../public/pos-sw.js'), 'utf8');

function worker(windows = []) {
  const listeners = {};
  const shown = [];
  const opened = [];
  const self = {
    location: { origin: 'https://cims.example' },
    addEventListener: (type, fn) => { listeners[type] = fn; },
    registration: { showNotification: (title, opts) => { shown.push({ title, ...opts }); return Promise.resolve(); } },
    clients: {
      matchAll: () => Promise.resolve(windows),
      openWindow: url => { opened.push(url); return Promise.resolve(); },
      claim: () => Promise.resolve(),
    },
    skipWaiting: () => Promise.resolve(),
  };
  new Function('self', 'caches', 'fetch', 'Response', src)(self, {}, () => {}, {});

  const fire = (type, event) => {
    let done = Promise.resolve();
    listeners[type]({ ...event, waitUntil: p => { done = p; } });
    return done;
  };
  return { fire, shown, opened };
}

const pushed = data => ({ data: { json: () => data } });
const notice = { id: 41, title: 'PR_1118 needs approval', body: '12,639.86 from Luzon Builders Supply', link: '/app/purchases/approvals', level: 'warning' };

test('a push with the system closed shows the notice', async () => {
  const sw = worker([]);
  await sw.fire('push', pushed(notice));

  assert.strictEqual(sw.shown.length, 1);
  assert.strictEqual(sw.shown[0].title, 'PR_1118 needs approval');
  assert.strictEqual(sw.shown[0].body, '12,639.86 from Luzon Builders Supply');
  // The same tag the bell uses, so one notice never appears twice.
  assert.strictEqual(sw.shown[0].tag, 'cims-41');
  assert.strictEqual(sw.shown[0].data.link, '/app/purchases/approvals');
});

test('with the system open on screen, the page is told instead', async () => {
  const told = [];
  const sw = worker([{ visibilityState: 'visible', focused: true, postMessage: m => told.push(m) }]);
  await sw.fire('push', pushed(notice));

  assert.strictEqual(sw.shown.length, 0);
  assert.deepStrictEqual(told, [{ type: 'cims-notification', id: 41 }]);
});

test('a system open only in a background tab still gets the notice', async () => {
  const sw = worker([{ visibilityState: 'hidden', focused: false, postMessage: () => {} }]);
  await sw.fire('push', pushed(notice));

  assert.strictEqual(sw.shown.length, 1);
});

test('tapping it opens the page it is about', async () => {
  const sw = worker([]);
  let closed = false;
  await sw.fire('notificationclick', { notification: { data: { link: '/app/purchases/approvals' }, close: () => { closed = true; } } });

  assert.ok(closed);
  assert.deepStrictEqual(sw.opened, ['/app/purchases/approvals']);
});

test('tapping it reuses a tab already open on the system', async () => {
  const went = [];
  const tab = { url: 'https://cims.example/app/dashboard', focus: () => Promise.resolve(tab), navigate: u => { went.push(u); return Promise.resolve(tab); } };
  const sw = worker([tab]);
  await sw.fire('notificationclick', { notification: { data: { link: '/app/purchases/approvals' }, close: () => {} } });

  assert.deepStrictEqual(went, ['/app/purchases/approvals']);
  assert.deepStrictEqual(sw.opened, []);
});

test('a link to somewhere else is not followed', async () => {
  const sw = worker([]);
  await sw.fire('notificationclick', { notification: { data: { link: 'https://evil.example/x' }, close: () => {} } });

  assert.deepStrictEqual(sw.opened, ['/app/dashboard']);
});
