/**
 * K85 — SC/PWD sale: the POS collects one centavo less than the server saves.
 * Red until repaired (.flow/DATATEST-2026-10-06.md).
 *
 * Drive (falsify-G7): Branch POS, HW-0008 at 18.90 (vatable, SC/PWD eligible),
 * PWD 1 of 1. The POS panel shows "VAT-Exempt Sales 16.87, PWD 20% - 3.38,
 * Grand Total 13.49" and takes 13.49; the server (app/utils/VatService.php)
 * saves GrandTotal 13.50, vat_exempt_sales 16.88, statutory_discount 3.38, so a
 * sale paid in full on screen is stored "partial" with 0.01 due.
 * By M2 the right figure is 18.90 / 1.12 = 16.875, less 20% = 13.50.
 *
 * Where they part: 18.90 / 1.12 is 16.874999999999996 in binary floating
 * point. PHP's round() pre-rounds and gives 16.88 and 13.50; the POS mirror
 * (Calcul_Vat in pos.vue) rounds with Math.round(v + EPSILON) and gives 16.87
 * and 13.49.
 *
 * The faulty logic is inline in the pos.vue component (no util to import), so
 * this test lifts the Calcul_Vat method out of the component source and runs
 * it with the state the POS has for that sale. The expected figures are the
 * ones the server stores for the same sale. If the repair moves the VAT mirror
 * into resources/src/utils, point this test at that function instead.
 *
 *   node --test tests/js/datatest-*.test.js
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const vue = readFileSync(join(__dirname, '../../resources/src/views/app/pages/pos.vue'), 'utf8');

/** The source of a component method `name() { ... }`, brace-matched. */
function methodSource(src, name) {
  const m = new RegExp('\\n\\s*' + name + '\\s*\\(([^)]*)\\)\\s*\\{').exec(src);
  if (!m) throw new Error(name + ' not found in pos.vue');
  const open = m.index + m[0].length - 1;
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}' && --depth === 0) {
      return { args: m[1], body: src.slice(open + 1, i) };
    }
  }
  throw new Error(name + ' is not closed');
}

const { args, body } = methodSource(vue, 'Calcul_Vat');
const Calcul_Vat = new Function(args, body);

/** The POS state for one vatable, eligible line at 18.90, PWD 1 of 1. */
function pwdSale(price) {
  return {
    currentUser: { vat_rate: 12, prices_include_vat: true },
    sale: { statutory_type: 'pwd', statutory_beneficiaries: 1, statutory_total_persons: 1, discount: 0, shipping: 0 },
    details: [{ subtotal: price, vat_class: 'vatable', sc_pwd_eligible: true }]
  };
}

test('K85: the POS total for an 18.90 PWD sale is the 13.50 the server saves', () => {
  const vat = Calcul_Vat.call(pwdSale(18.9));
  assert.strictEqual(vat.total_due, 13.5, 'POS collects ' + vat.total_due + ', server saves GrandTotal 13.50');
});

test('K85: the POS VAT-exempt figure for an 18.90 PWD sale is the 16.88 the server saves', () => {
  const vat = Calcul_Vat.call(pwdSale(18.9));
  assert.strictEqual(vat.vat_exempt_sales, 16.88, 'POS shows ' + vat.vat_exempt_sales + ', server saves 16.88');
  assert.strictEqual(vat.statutory_discount, 3.38, 'the 20% discount is 3.38 on both sides');
});
