/**
 * What the sign-in page does with the server's answer.
 *
 * With two-factor on, a correct password answers `two_factor_required` and
 * nobody is signed in yet. The page used to reload regardless, so the person
 * landed back on the sign-in page with no way in.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/signInFlow.js'), 'utf8');
const { afterPassword, refusal } = new Function(src.replace(/export function/g, 'function') + '\nreturn { afterPassword, refusal };')();

test('two-factor on: ask for the code, do not reload', () => {
  assert.strictEqual(afterPassword({ two_factor_required: true, message: 'Enter the code' }), 'code');
});

test('no two-factor: signed in', () => {
  assert.strictEqual(afterPassword({ success: true }), 'signed_in');
});

test('a refusal is shown in the server\'s words', () => {
  assert.strictEqual(refusal({ errors: { code: ['That code is not right.'] } }, 'Incorrect'), 'That code is not right.');
  assert.strictEqual(refusal({ errors: { email: ['This account is locked. Try again in 15 minutes.'] } }, 'Incorrect'), 'This account is locked. Try again in 15 minutes.');
  assert.strictEqual(refusal({ message: 'Too many attempts.' }, 'Incorrect'), 'Too many attempts.');
  assert.strictEqual(refusal(null, 'Incorrect'), 'Incorrect');
});

test('the generic "given data was invalid" is not shown', () => {
  assert.strictEqual(refusal({ message: 'The given data was invalid.' }, 'Incorrect'), 'Incorrect');
});
