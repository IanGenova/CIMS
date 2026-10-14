/**
 * The sidebar drawer on phones and tablets.
 *
 * Found in the responsive audit: after every page change at 1200px or less the
 * router *toggled* the sidebar, meaning to close it. A closed drawer therefore
 * opened on every other navigation and covered the page on a phone.
 *
 *   node --test tests/js/
 *
 * The store module is plain ES module source; it is loaded here without a
 * bundler by turning its `export default` into a return value.
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

function loadModule() {
  const src = readFileSync(join(__dirname, '../../resources/src/store/modules/largeSidebar.js'), 'utf8')
    .replace(/export default/, 'return');
  return new Function(src)();
}

function makeStore(initial) {
  const mod = loadModule();
  const state = JSON.parse(JSON.stringify(mod.state));
  Object.assign(state.sidebarToggleProperties, initial);
  const commit = (type, payload) => mod.mutations[type](state, payload);
  const dispatch = (type, payload) => mod.actions[type]({ commit, state }, payload);
  return { state, dispatch, props: () => state.sidebarToggleProperties };
}

test('setSidebarOpen(false) leaves a closed sidebar closed', () => {
  const s = makeStore({ isSideNavOpen: false });
  s.dispatch('setSidebarOpen', false);
  assert.strictEqual(s.props().isSideNavOpen, false);
});

test('setSidebarOpen(false) closes an open sidebar', () => {
  const s = makeStore({ isSideNavOpen: true });
  s.dispatch('setSidebarOpen', false);
  assert.strictEqual(s.props().isSideNavOpen, false);
});

test('a page change on a phone never opens a closed drawer', () => {
  const s = makeStore({ isSideNavOpen: false });
  for (let i = 0; i < 3; i++) {
    s.dispatch('closeDrawersOnNarrow', 375);
    assert.strictEqual(s.props().isSideNavOpen, false, `after navigation ${i + 1}`);
  }
});

test('a page change on a tablet closes both drawers', () => {
  const s = makeStore({ isSideNavOpen: true, isSecondarySideNavOpen: true });
  s.dispatch('closeDrawersOnNarrow', 768);
  assert.strictEqual(s.props().isSideNavOpen, false);
  assert.strictEqual(s.props().isSecondarySideNavOpen, false);
});

test('a page change on a desktop keeps the sidebar and closes only the flyout', () => {
  const s = makeStore({ isSideNavOpen: true, isSecondarySideNavOpen: true });
  s.dispatch('closeDrawersOnNarrow', 1920);
  assert.strictEqual(s.props().isSideNavOpen, true);
  assert.strictEqual(s.props().isSecondarySideNavOpen, false);
});
