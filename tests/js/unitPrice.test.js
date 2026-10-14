/**
 * Changing a line's unit changes its price.
 *
 * Found testing CIMS as a pharmacy: a strip of 10 Paracetamol (₱4.50 a tablet)
 * rang up at ₱4.50 and a box of 100 Metformin (₱5.00 a tablet) at ₱5.00. The
 * stock moved by the strip and the box; the price stayed per tablet. The same
 * picker sits on every sale, quotation, return and purchase line.
 *
 * Units are defined against a base unit: operator "*" value 10 means one strip
 * is 10 pieces; operator "/" value 1000 means one gram is a thousandth of a kilo.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/unitPrice.js'), 'utf8');
const { repriceForUnit } = new Function(src.replace(/export function/g, 'function') + '\nreturn { repriceForUnit };')();

const piece = { id: 1, operator: '*', operator_value: 1 };
const strip = { id: 2, operator: '*', operator_value: 10 };
const box = { id: 3, operator: '*', operator_value: 100 };
const kilo = { id: 4, operator: '*', operator_value: 1 };
const gram = { id: 5, operator: '/', operator_value: 1000 };

test('piece to strip of 10: ten times the price', () => {
  assert.strictEqual(repriceForUnit(4.5, piece, strip), 45);
});

test('piece to box of 100', () => {
  assert.strictEqual(repriceForUnit(5, piece, box), 500);
});

test('strip back to piece', () => {
  assert.strictEqual(repriceForUnit(45, strip, piece), 4.5);
});

test('strip to box keeps the per-piece price', () => {
  assert.strictEqual(repriceForUnit(45, strip, box), 450);
});

test('a "/" unit: kilo to gram', () => {
  assert.strictEqual(repriceForUnit(120, kilo, gram), 0.12);
});

test('a price the cashier typed is scaled, not replaced', () => {
  // 4.00 a tablet after a manual markdown -> 40.00 a strip.
  assert.strictEqual(repriceForUnit(4, piece, strip), 40);
});

test('same unit or an unknown unit: price unchanged', () => {
  assert.strictEqual(repriceForUnit(45, strip, strip), 45);
  assert.strictEqual(repriceForUnit(45, strip, undefined), 45);
  assert.strictEqual(repriceForUnit(45, undefined, strip), 45);
});

test('rounded to the centavo', () => {
  assert.strictEqual(repriceForUnit(10, box, strip), 1);
  assert.strictEqual(repriceForUnit(7, { operator: '*', operator_value: 3 }, piece), 2.33);
});
