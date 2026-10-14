/**
 * K95 — Duplicate product code: the Create product screen says "Invalid Data !!"
 * instead of the server's reason.
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 *
 * Drive (falsify-G7): Create product with code HW-0004, which exists. The server
 * answers 422 {"errors":{"code":["This code already used. Generate Now"]}}; the
 * screen raises the toast "Failed × Invalid Data !!". (Correction to G7's text:
 * its own screenshot k95_duplicate_code.png shows the reason as an alert under
 * the Code field as well -- the defect is the generic toast that contradicts
 * it, not a missing field message.)
 *
 * The handler is inline in Add_product.vue (Create_Product's .catch), so this
 * test lifts Create_Product out of the component source and runs it with an
 * axios that fails the way the app's interceptor delivers a 422 (through
 * resources/src/utils/apiError.js), then reads what the screen would show.
 *
 *   node --test tests/js/datatest-*.test.js
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const SRC = join(__dirname, '../../resources/src');
const vue = readFileSync(join(SRC, 'views/app/pages/products/Add_product.vue'), 'utf8');
const en = new Function(readFileSync(join(SRC, 'translations/locales/en.js'), 'utf8').replace(/export default/, 'return'))();
const apiSrc = readFileSync(join(SRC, 'utils/apiError.js'), 'utf8');
const { rejection } = new Function(apiSrc.replace(/export function/g, 'function') + '\nreturn { rejection };')();

function methodSource(src, name) {
  const m = new RegExp('\\n\\s*' + name + '\\s*\\(([^)]*)\\)\\s*\\{').exec(src);
  if (!m) throw new Error(name + ' not found in Add_product.vue');
  const open = m.index + m[0].length - 1;
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}' && --depth === 0) return { args: m[1], body: src.slice(open + 1, i) };
  }
  throw new Error(name + ' is not closed');
}

const { args, body } = methodSource(vue, 'Create_Product');

/** Runs Create_Product against a server that answers 422 with this body. */
async function submitAgainst(status, data) {
  const toasts = [];
  let settle;
  const done = new Promise(r => { settle = r; });
  const failure = rejection({ message: 'Request failed with status code ' + status, response: { status, data } });
  const axios = { post: () => { const p = Promise.reject(failure); p.catch(() => {}); setTimeout(settle, 20); return p; } };
  const NProgress = { start() {}, set() {}, done() {} };
  const vm = {
    product: { name: 'Dup Code Refuter', code: 'HW-0004', is_variant: false },
    variants: [], images: [], data: new FormData(), code_exist: '', SubmitProcessing: false,
    $t: k => (k in en ? en[k] : k),
    $router: { push() {} },
    makeToast: (variant, msg, title) => toasts.push({ variant, msg, title })
  };
  const run = new Function('axios', 'NProgress', 'return function (' + args + ') {' + body + '};')(axios, NProgress);
  run.call(vm);
  await done;
  return { vm, toasts };
}

test('K95: a duplicate code is reported with the server\'s reason, not "Invalid Data !!"', async () => {
  const reason = 'This code already used. Generate Now';
  const { toasts } = await submitAgainst(422, { errors: { code: [reason] } });

  const generic = toasts.filter(t => t.msg === en.InvalidData);
  assert.deepStrictEqual(generic.map(t => t.msg), [],
    'the screen must not answer the server\'s "' + reason + '" with the generic "' + en.InvalidData + '"');
});
