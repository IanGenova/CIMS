/**
 * The router's after-navigation hook must close the sidebar drawers, not flip
 * them. The rule itself lives in the store (closeDrawersOnNarrow, tested in
 * largeSidebar.test.js); this pins the router to calling it, because the old
 * hook toggled the sidebar and opened a closed drawer on every other page.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/router.js'), 'utf8');
const afterEach = src.slice(src.indexOf('router.afterEach('), src.indexOf('});', src.indexOf('router.afterEach(')) + 3);

test('afterEach exists', () => {
  assert.ok(afterEach.length > 20, 'router.afterEach not found');
});

test('afterEach closes the drawers through the store rule', () => {
  assert.match(afterEach, /dispatch\(\s*["']closeDrawersOnNarrow["']\s*,\s*window\.innerWidth\s*\)/);
});

test('afterEach never toggles the sidebar', () => {
  assert.doesNotMatch(afterEach, /changeSidebarProperties/);
});
