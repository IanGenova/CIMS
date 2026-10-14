/**
 * main.js must reject failed requests through utils/apiError.js, so every
 * screen gets the server's own reason (see apiError.test.js).
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const main = readFileSync(join(__dirname, '../../resources/src/main.js'), 'utf8');

test('main.js imports the shared rejection', () => {
  assert.match(main, /import\s*\{\s*rejection\s*\}\s*from\s*["']\.\/utils\/apiError["']/);
});

test('the response interceptor rejects through it', () => {
  const start = main.indexOf('axios.interceptors.response.use(');
  assert.ok(start >= 0, 'no response interceptor');
  // The interceptor ends at the first "});" that starts a line.
  const end = main.indexOf('\n});', start);
  const block = main.slice(start, end + 4);
  assert.match(block, /Promise\.reject\(\s*rejection\(\s*error\s*\)\s*\)/);
  assert.doesNotMatch(block, /Promise\.reject\(\s*error\.response\.data\s*\)/);
});
