/**
 * Which notices pop up: the unread ones that arrived since the last look.
 *
 * The bell polls; each poll returns the latest notices. Only new, unread ones
 * become pop-ups and desktop notifications, and the first load after opening
 * the app only sets the starting point -- a morning's backlog belongs in the
 * bell, not in a burst of twenty pop-ups.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/notifyDiff.js'), 'utf8');
const { arrivals, whenSaid } = new Function(src.replace(/export function/g, 'function')
  + '\nreturn { arrivals, whenSaid: typeof whenSaid === "function" ? whenSaid : () => "missing" };')();

const n = (id, is_read = false) => ({ id, title: 'N' + id, is_read });

test('the first look only sets the starting point', () => {
  const r = arrivals([n(12), n(11), n(10)], null);
  assert.deepStrictEqual(r.fresh, []);
  assert.strictEqual(r.lastSeenId, 12);
});

test('later looks pop only what is new, oldest first', () => {
  const r = arrivals([n(15), n(14), n(12), n(11)], 12);
  assert.deepStrictEqual(r.fresh.map(x => x.id), [14, 15]);
  assert.strictEqual(r.lastSeenId, 15);
});

test('something already read elsewhere does not pop', () => {
  const r = arrivals([n(15, true), n(14)], 12);
  assert.deepStrictEqual(r.fresh.map(x => x.id), [14]);
  assert.strictEqual(r.lastSeenId, 15);
});

test('nothing new, nothing pops, and the mark never goes backwards', () => {
  assert.deepStrictEqual(arrivals([n(12)], 12), { fresh: [], lastSeenId: 12 });
  assert.deepStrictEqual(arrivals([], 12), { fresh: [], lastSeenId: 12 });
});

// When each notice happened, as the bell shows it. The server sends
// "2026-10-03 08:45:30" in the shop's own time; the bell answers in words.
test('each notice says when it happened', () => {
  const now = new Date(2026, 9, 3, 11, 10, 0); // 3 Oct 2026, 11:10 local
  assert.strictEqual(whenSaid('2026-10-03 11:09:40', now), 'Just now');
  assert.strictEqual(whenSaid('2026-10-03 11:02:00', now), '8 min ago');
  assert.strictEqual(whenSaid('2026-10-03 08:45:30', now), '2 hr ago');
  assert.match(whenSaid('2026-10-02 08:00:02', now), /^Yesterday 8:00/);
  assert.match(whenSaid('2026-09-29 11:30:02', now), /^Sep 29/);
  assert.strictEqual(whenSaid(null, now), '');
});
