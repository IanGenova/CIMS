/**
 * What a failed request hands to a screen's .catch().
 *
 * The app's axios interceptor rejected with the response body. Some screens
 * read that (error.message, error.errors); 24 others were written for axios's
 * own shape (error.response.data), which was therefore always undefined, so
 * the server's reason ("This purchase is awaiting approval and cannot be
 * received yet") was replaced by "Invalid Data !!". Found while testing the
 * Stockman account. Both shapes must work.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/apiError.js'), 'utf8');
const { rejection } = new Function(src.replace(/export function/g, 'function') + '\nreturn { rejection };')();

const failed = (status, data) => ({ message: 'Request failed with status code ' + status, response: { status, data } });

test('the body is what the screen gets, as before', () => {
  const body = { message: 'The given data was invalid.', errors: { qty: ['Too many'] } };
  const r = rejection(failed(422, body));
  assert.strictEqual(r.message, 'The given data was invalid.');
  assert.deepStrictEqual(r.errors, { qty: ['Too many'] });
});

test('error.response.data reaches the same body', () => {
  const body = { message: 'This purchase is awaiting approval and cannot be received yet.' };
  const r = rejection(failed(422, body));
  assert.strictEqual(r.response.data.message, body.message);
  assert.strictEqual(r.response.status, 422);
});

test('the added response does not change what the body serialises to', () => {
  const body = { message: 'x' };
  const r = rejection(failed(422, body));
  assert.strictEqual(JSON.stringify(r), '{"message":"x"}');
});

test('a text body is passed on unchanged', () => {
  assert.strictEqual(rejection(failed(500, 'Server Error')), 'Server Error');
});

test('no response at all (network down) gives the message, as before', () => {
  assert.strictEqual(rejection({ message: 'Network Error' }), 'Network Error');
});
