/**
 * Every screen a role may use must be reachable from the sidebar.
 *
 * Found by signing in as test roles (Stockman, Cashier, Purchaser). The
 * sidebar shows a top-level item only when the user holds one of the
 * permissions listed on it, and the sub-items carry their own conditions. When
 * the two lists disagreed the screen was allowed but unreachable: a Stockman
 * could book in deliveries but had no menu path to Goods Receipts (filed under
 * Purchases), Expenses had no top-level item at all, and Till Sessions checked
 * a permission name that does not exist ('Sales_pos').
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '../..');
const sidebar = fs.readFileSync(path.join(ROOT, 'resources/src/containers/layouts/largeSidebar/Sidebar.vue'), 'utf8');

const perms = cond => [...cond.matchAll(/includes\('([^']+)'\)/g)].map(m => m[1]);

// Top-level items: <li v-show="..." ... data-item="x"
const top = {};
for (const m of sidebar.matchAll(/<li\s+v-show="([\s\S]*?)"[\s\S]*?data-item="([^"]+)"/g)) {
  top[m[2]] = perms(m[1]);
}
// Plain top-level items with no condition (Dashboard, POS) are found by data-item alone.
for (const m of sidebar.matchAll(/data-item="([^"]+)"/g)) {
  if (!(m[1] in top)) top[m[1]] = null;
}

// Sub-menus: <ul ... data-parent="x"> ... </ul>
const children = {};
for (const m of sidebar.matchAll(/data-parent="([^"]+)"([\s\S]*?)<\/ul>/g)) {
  children[m[1]] = [...new Set(perms(m[2]))];
}

// Every permission that exists: the seeder plus migrations that add more.
function knownPermissions() {
  const names = new Set();
  const seeder = fs.readFileSync(path.join(ROOT, 'database/seeds/PermissionsSeeder.php'), 'utf8');
  for (const m of seeder.matchAll(/'name'\s*=>\s*'([^']+)'/g)) names.add(m[1]);
  const dir = path.join(ROOT, 'database/migrations');
  for (const f of fs.readdirSync(dir)) {
    const src = fs.readFileSync(path.join(dir, f), 'utf8');
    const block = src.match(/\$permissions\s*=\s*\[([\s\S]*?)\];/);
    if (block) for (const m of block[1].matchAll(/'([^']+)'\s*=>/g)) names.add(m[1]);
  }
  return names;
}

test('every sub-menu has a top-level item to open it', () => {
  const orphans = Object.keys(children).filter(p => !(p in top));
  assert.deepStrictEqual(orphans, []);
});

test('a top-level item shows whenever any of its sub-items can', () => {
  const unreachable = [];
  for (const [parent, needed] of Object.entries(children)) {
    const shownFor = top[parent];
    if (shownFor === null || shownFor === undefined) continue; // unconditional, or reported above
    for (const p of needed) if (!shownFor.includes(p)) unreachable.push(`${parent}: ${p}`);
  }
  assert.deepStrictEqual(unreachable, []);
});

test('every permission the sidebar checks exists', () => {
  const known = knownPermissions();
  const unknown = [...new Set(perms(sidebar))].filter(p => !known.has(p));
  assert.deepStrictEqual(unknown, []);
});
