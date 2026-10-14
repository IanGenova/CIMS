/**
 * The warehouse a transaction starts in.
 *
 * Every screen is sent only the warehouses its user may work in (a cashier tied
 * to Branch Warehouse gets just that one). When there is exactly one, the
 * screen starts in it instead of asking -- the owner's ask: "the cashier ... must
 * be automatically default of the warehouse when they do transactions".
 * Something already chosen (an edit, a quotation turned into a sale) is kept.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/defaultWarehouse.js'), 'utf8');
const { startingWarehouse } = new Function(src.replace(/export function/g, 'function') + '\nreturn { startingWarehouse };')();

const branch = [{ id: 2, name: 'Branch Warehouse' }];
const both = [{ id: 1, name: 'Main Warehouse' }, { id: 2, name: 'Branch Warehouse' }];

test('one warehouse: start in it', () => {
  assert.strictEqual(startingWarehouse(branch, ''), 2);
  assert.strictEqual(startingWarehouse(branch, null), 2);
  assert.strictEqual(startingWarehouse(branch, undefined), 2);
});

test('several warehouses: the person chooses', () => {
  assert.strictEqual(startingWarehouse(both, ''), null);
});

test('several, with a shop default they may use: start there', () => {
  assert.strictEqual(startingWarehouse(both, '', 1), 1);
});

test('a shop default they may not use is ignored', () => {
  assert.strictEqual(startingWarehouse(branch, '', 1), 2);
  assert.strictEqual(startingWarehouse(both, '', 9), null);
});

test('something already chosen is kept', () => {
  assert.strictEqual(startingWarehouse(branch, 7), null);
});

test('no list yet: nothing', () => {
  assert.strictEqual(startingWarehouse(undefined, ''), null);
  assert.strictEqual(startingWarehouse([], ''), null);
});
