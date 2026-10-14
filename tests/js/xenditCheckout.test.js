/**
 * The cashier's side of a Xendit payment: start a checkout for the amount,
 * then keep asking the server until the customer has paid (or it has
 * expired / been cancelled). The server asks Xendit; the browser never talks
 * to Xendit and never decides on its own that money arrived.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/xenditCheckout.js'), 'utf8');
const lib = new Function(src.replace(/export function/g, 'function').replace(/export const/g, 'const')
  + '\nreturn { startCheckout, watchCheckout, isFinal };')();

const fakeTimers = () => {
  const queue = [];
  return {
    set: (fn, ms) => { queue.push({ fn, ms }); return queue.length; },
    clear: () => { queue.length = 0; },
    runNext: async () => { const t = queue.shift(); if (t) { await t.fn(); } return !!t; },
    pending: () => queue.length,
  };
};

test('a checkout is started for this sale and amount', async () => {
  const calls = [];
  const http = { post: (url, body) => { calls.push([url, body]); return Promise.resolve({ data: { checkout: { id: 7, status: 'pending' } } }); } };
  const c = await lib.startCheckout(http, 41, 1250.5);

  assert.deepStrictEqual(calls, [['xendit/checkouts', { sale_id: 41, amount: 1250.5 }]]);
  assert.strictEqual(c.id, 7);
});

test('it keeps asking until the customer has paid, then stops', async () => {
  const answers = ['pending', 'pending', 'paid'];
  const asked = [];
  const http = { get: url => { asked.push(url); return Promise.resolve({ data: { checkout: { id: 7, status: answers.shift() } } }); } };
  const timers = fakeTimers();
  const seen = [];
  lib.watchCheckout(http, 7, c => seen.push(c.status), { every: 3000, timers });

  while (await timers.runNext()) { /* each tick asks once */ }

  assert.deepStrictEqual(seen, ['pending', 'pending', 'paid']);
  assert.deepStrictEqual(asked, ['xendit/checkouts/7', 'xendit/checkouts/7', 'xendit/checkouts/7']);
  assert.strictEqual(timers.pending(), 0, 'no more asking once it is paid');
});

test('a dropped connection does not end the wait', async () => {
  let n = 0;
  const http = { get: () => (++n === 1 ? Promise.reject(new Error('offline')) : Promise.resolve({ data: { checkout: { status: 'expired' } } })) };
  const timers = fakeTimers();
  const seen = [];
  lib.watchCheckout(http, 7, c => seen.push(c.status), { every: 3000, timers });

  while (await timers.runNext()) { /* */ }

  assert.deepStrictEqual(seen, ['expired']);
});

test('closing the window stops the asking', async () => {
  const http = { get: () => Promise.resolve({ data: { checkout: { status: 'pending' } } }) };
  const timers = fakeTimers();
  const stop = lib.watchCheckout(http, 7, () => {}, { every: 3000, timers });
  stop();

  assert.strictEqual(await timers.runNext(), false);
});

test('paid, expired and cancelled are the end; pending is not', () => {
  assert.ok(lib.isFinal('paid'));
  assert.ok(lib.isFinal('expired'));
  assert.ok(lib.isFinal('cancelled'));
  assert.ok(!lib.isFinal('pending'));
});
