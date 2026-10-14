// Shared harness for the data-test race scripts (K<n>-*.mjs).
//
// Two PHP dev servers on the same disposable database stand in for two tills.
// Each race fires one request at each server at the same moment, then reads
// the rows back with mysql. Requests go through curl (not Node fetch, which
// gets spurious 401s from the PHP dev servers). Tokens come from
// tests/fixtures/datatest-accounts.json and are never printed.
//
//   RACE_A   first server  (default http://127.0.0.1:8014)
//   RACE_B   second server (default http://127.0.0.1:8024)
//   RACE_DB  database both servers use (default cims_dt4) -- a disposable copy only
//   RACE_ROUNDS  rounds (default per script, usually 20); a number as argv[2] also works
import { spawn, execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const strip = (u) => u.replace(/\/+$/, '');
export const A = strip(process.env.RACE_A || 'http://127.0.0.1:8014') + '/api';
export const B = strip(process.env.RACE_B || 'http://127.0.0.1:8024') + '/api';
export const DB = process.env.RACE_DB || 'cims_dt4';
const MYSQL = process.env.RACE_MYSQL || 'C:/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe';
const CURL = process.env.RACE_CURL || 'curl';

if (!/^cims_dt\d+p?$/.test(DB)) {
  console.error(`RACE_DB=${DB}: races only run against a disposable data-test copy (cims_dt*).`);
  process.exit(2);
}

export function rounds(dflt = 20) {
  const n = Number(process.argv[2] || process.env.RACE_ROUNDS || dflt);
  return Number.isFinite(n) && n > 0 ? Math.floor(n) : dflt;
}

const ACC = JSON.parse(readFileSync(path.join(HERE, '..', 'fixtures', 'datatest-accounts.json'), 'utf8'));
const TOKENS = {};
for (const a of ACC.hardware_accounts) TOKENS[a.username] = a.token;
export function token(username) {
  if (!TOKENS[username]) throw new Error(`no hardware account ${username} in datatest-accounts.json`);
  return TOKENS[username];
}

// ------------------------------------------------------------------ SQL

// The mysql client on this machine now and then returns an empty stdout, so a
// SELECT that comes back empty is retried rather than read as "no rows".
export function sql(q) {
  const tries = /^\s*select/i.test(q) ? 6 : 1;
  let rows = [];
  for (let i = 0; i < tries; i++) {
    const out = execFileSync(MYSQL, ['-uroot', DB, '-N', '-B', '-e', q], { encoding: 'utf8' });
    rows = out.trim().split(/\r?\n/).filter(Boolean).map((l) => l.split('\t'));
    if (rows.length) break;
  }
  return rows;
}
export const one = (q) => { const r = sql(q); if (!r.length) throw new Error('no row: ' + q); return r[0][0]; };
export const num = (q) => Number(one(q));

export function setQte(productId, wh, qte) {
  sql(`UPDATE product_warehouse SET qte=${qte} WHERE product_id=${productId} AND warehouse_id=${wh} AND deleted_at IS NULL AND product_variant_id IS NULL`);
}
export const qte = (productId, wh) => num(`SELECT qte+0 FROM product_warehouse WHERE product_id=${productId} AND warehouse_id=${wh} AND deleted_at IS NULL AND product_variant_id IS NULL`);
export const maxId = (table) => num(`SELECT COALESCE(MAX(id),0) FROM ${table}`);
export const movesSince = (productId, wh, id) => num(`SELECT COALESCE(SUM(quantity),0)+0 FROM stock_movements WHERE product_id=${productId} AND warehouse_id=${wh} AND id>${id}`);

// ------------------------------------------------------------------ HTTP

// Start one curl. The body goes in on stdin (--data-binary @-), and curl reads
// all of stdin before it connects, so the request leaves only when go() is
// called. That makes the two sides of a race a real barrier, not two spawns
// a few milliseconds apart.
function prepare(base, user, method, p, body) {
  const args = ['-s', '-S', '-X', method, base + p,
    '-H', 'Authorization: Bearer ' + token(user), '-H', 'Accept: application/json',
    '-H', 'Content-Type: application/json', '--data-binary', '@-', '-w', '\n%{http_code}', '-m', '60'];
  const child = spawn(CURL, args, { env: { ...process.env, MSYS_NO_PATHCONV: '1' }, windowsHide: true });
  let out = '';
  let err = '';
  child.stdout.on('data', (d) => { out += d; });
  child.stderr.on('data', (d) => { err += d; });
  const done = new Promise((resolve) => {
    child.on('close', () => {
      const i = out.lastIndexOf('\n');
      const status = Number(out.slice(i + 1)) || 0;
      const text = i >= 0 ? out.slice(0, i) : out;
      let json = null;
      try { json = JSON.parse(text); } catch { /* not JSON */ }
      resolve({ status, json, text: text.slice(0, 400), err: err.slice(0, 200) });
    });
  });
  const payload = body === undefined || body === null ? '' : JSON.stringify(body);
  return { go: () => child.stdin.end(payload), done };
}

export async function api(base, user, method, p, body) {
  const r = prepare(base, user, method, p, body);
  r.go();
  return r.done;
}

// Both curls are started, given time to come up, then released together.
// a and b are [base, user, method, path, body]. opts.delayB (ms) releases B
// that much after A, for races whose window is not at t=0.
export async function race(a, b, opts = {}) {
  const ra = prepare(...a);
  const rb = prepare(...b);
  await sleep(250);
  ra.go();
  if (opts.delayB) await sleep(opts.delayB);
  rb.go();
  return Promise.all([ra.done, rb.done]);
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Manila date, as the app stamps documents.
export const today = () => new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);

// ------------------------------------------------------------------ bodies

export function line(productId, unitId, qty, price, extra = {}) {
  return {
    product_id: productId, product_variant_id: null, sale_unit_id: unitId, purchase_unit_id: unitId,
    quantity: qty, Unit_price: price, Unit_cost: price, subtotal: price * qty, Net_cost: price,
    discount: 0, discount_Method: '2', tax_percent: 0, tax_method: '1', name: 'p' + productId, ...extra,
  };
}

// A POS sale with nothing tendered.
export function posBody(wh, productId, unitId, qty, price, extra = {}) {
  const l = line(productId, unitId, qty, price);
  return {
    client_id: 1, warehouse_id: wh, GrandTotal: l.subtotal, amount: 0, received_amount: 0, change: 0,
    tax_rate: 0, TaxNet: 0, discount: 0, shipping: 0, payment: { Reglement: 'Cash' }, details: [l], ...extra,
  };
}

export function purchaseBody(wh, productId, unitId, qty, cost, statut) {
  const l = line(productId, unitId, qty, cost, { no_unit: 1 });
  return {
    supplier_id: 15, warehouse_id: wh, date: today(), statut, notes: 'race', tax_rate: 0, discount: 0, shipping: 0,
    GrandTotal: l.subtotal, details: [l],
  };
}

// ------------------------------------------------------------------ report

export function short(r) {
  return `${r.status} ${(r.text || r.err || '').replace(/\s+/g, ' ').slice(0, 160)}`;
}

// Print the K line and exit: 1 when any round broke the rule.
export function verdict(k, bad, n, rule, extra = '') {
  console.log(`${k}: ${bad}/${n} rounds violated ${rule}${extra ? ' (' + extra + ')' : ''}`);
  process.exit(bad > 0 ? 1 : 0);
}
