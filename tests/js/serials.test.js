/**
 * Serial numbers on a line: one per unit, each once.
 *
 * Asked for so CIMS fits appliance, electronics and phone shops. Every screen
 * that moves a serial-tracked product asks which units; this is the check the
 * screen runs before saving, worded the way the server words its refusal
 * (app/utils/SerialService.php), so the cashier sees it before a round trip.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/serials.js'), 'utf8');
const { parseSerials, baseUnits, serialProblem, firstSerialProblem } = new Function(
  src.replace(/export function/g, 'function') + '\nreturn { parseSerials, baseUnits, serialProblem, firstSerialProblem };')();

const pc = { operator: '*', operator_value: 1 };
const box2 = { operator: '*', operator_value: 2 };

test('scanned or typed serials are split on lines and commas, trimmed, blanks dropped', () => {
  assert.deepStrictEqual(parseSerials(' SN-001\r\nSN-002,, \nSN-003 \n'), ['SN-001', 'SN-002', 'SN-003']);
  assert.deepStrictEqual(parseSerials(['  A1 ', '', 'B2']), ['A1', 'B2']);
  assert.deepStrictEqual(parseSerials(null), []);
});

test('the units a line needs serials for are counted in the base unit', () => {
  assert.strictEqual(baseUnits(3, pc), 3);
  assert.strictEqual(baseUnits(2, box2), 4);
  assert.strictEqual(baseUnits(500, { operator: '/', operator_value: 1000 }), 0.5);
  assert.strictEqual(baseUnits(2, null), 2);
});

test('a line with exactly one serial per unit is fine', () => {
  assert.strictEqual(serialProblem(['A1', 'A2'], 2, 'Aircon'), null);
});

test('too few, too many, a repeat, or a part unit is explained', () => {
  assert.strictEqual(serialProblem(['A1'], 3, 'Aircon'), 'Aircon: 2 more serial numbers needed (3 units, 1 given).');
  assert.strictEqual(serialProblem([], 1, 'Aircon'), 'Aircon: 1 more serial number needed (1 unit, 0 given).');
  assert.strictEqual(serialProblem(['A1', 'A2', 'A3'], 2, 'Aircon'), 'Aircon: 1 serial number too many (2 units, 3 given).');
  assert.strictEqual(serialProblem(['A1', 'a1'], 2, 'Aircon'), 'Aircon: serial number a1 is listed twice.');
  assert.strictEqual(serialProblem([], 1.5, 'Aircon'), 'Aircon is counted in whole units, and 1.5 is not a whole number.');
});

test('before saving, the first serial-tracked line with a problem is named; other lines are ignored', () => {
  const lines = [
    { name: 'Cement', has_serials: false, quantity: 10 },
    { name: 'Aircon', has_serials: true, quantity: 2, serials: ['A1', 'A2'] },
    { name: 'Smart TV', has_serials: true, quantity: 1 },
  ];
  assert.strictEqual(firstSerialProblem(lines), 'Smart TV: 1 more serial number needed (1 unit, 0 given).');
  assert.strictEqual(firstSerialProblem(lines.slice(0, 2)), null);
});

test('a line sold by the box counts its serials by the piece', () => {
  const units = [{ id: 7, operator: '*', operator_value: 2 }];
  const line = { name: 'Phone', has_serials: true, quantity: 1, sale_unit_id: 7, serials: ['P1'] };
  assert.strictEqual(firstSerialProblem([line], (l) => units.find((u) => u.id === l.sale_unit_id)),
    'Phone: 1 more serial number needed (2 units, 1 given).');
});

// Found after release: the badge counted boxes while the server counted pieces,
// so a box of 2 phones showed "Serials 1/1" and was then refused.
const { unitFactor, lineUnits } = new Function(
  src.replace(/export function/g, 'function') + '\nreturn { unitFactor, lineUnits };')();

test('a unit says how many base units one of it holds', () => {
  assert.strictEqual(unitFactor({ operator: '*', operator_value: 2 }), 2);
  assert.strictEqual(unitFactor({ operator: '/', operator_value: 4 }), 0.25);
  assert.strictEqual(unitFactor(null), 1);
});

test('a line needs serials for its quantity times its unit factor', () => {
  assert.strictEqual(lineUnits({ quantity: 3, unit_factor: 2 }), 6);
  assert.strictEqual(lineUnits({ quantity: 3 }), 3);
  assert.strictEqual(lineUnits({ quantity: '2', unit_factor: '10' }), 20);
});

test('before saving, a box line is checked by the piece without being told the unit', () => {
  const line = { name: 'Phone', has_serials: true, quantity: 2, unit_factor: 2, serials: ['P1', 'P2'] };
  assert.strictEqual(firstSerialProblem([line]), 'Phone: 2 more serial numbers needed (4 units, 2 given).');
  assert.strictEqual(firstSerialProblem([{ ...line, serials: ['P1', 'P2', 'P3', 'P4'] }]), null);
});
