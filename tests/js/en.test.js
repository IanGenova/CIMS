/**
 * Every translation key a screen asks for must exist in the English file.
 *
 * vue-i18n shows the key itself when it is missing. A key written as a phrase
 * ("Add Branch") still reads fine, but a code-style key leaks onto the screen
 * as "Branch_Info" or "GrandTotal". Found in the responsive audit on the sale
 * detail page.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const SRC = path.join(__dirname, '../../resources/src');
const en = new Function(fs.readFileSync(path.join(SRC, 'translations/locales/en.js'), 'utf8').replace(/export default/, 'return'))();
const has = key => { let o = en; for (const p of key.split('.')) { if (o == null || !(p in o)) return false; o = o[p]; } return true; };

function files(dir) {
  return fs.readdirSync(dir).flatMap(f => {
    const p = path.join(dir, f);
    return fs.statSync(p).isDirectory() ? files(p) : /\.(vue|js)$/.test(f) ? [p] : [];
  });
}

test('no code-style translation key is missing from en.js', () => {
  const missing = [];
  for (const file of [...files(path.join(SRC, 'views')), ...files(path.join(SRC, 'containers'))]) {
    const src = fs.readFileSync(file, 'utf8');
    for (const m of src.matchAll(/\$t\(\s*['"]([^'"\s]+)['"]\s*\)/g)) {
      const key = m[1];
      const codeStyle = key.includes('_') || /[a-z][A-Z]/.test(key);
      if (codeStyle && !has(key)) missing.push(`${key}  (${path.relative(SRC, file)})`);
    }
  }
  assert.deepStrictEqual([...new Set(missing)], []);
});
