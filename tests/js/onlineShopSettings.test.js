const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const compiler = require('vue-template-compiler');
const buildFullPath = require('axios/lib/core/buildFullPath');

const source = fs.readFileSync(path.join(__dirname, '../../resources/src/views/app/pages/settings/online_shop.vue'), 'utf8');
const script = compiler.parseComponent(source).script.content;

function componentWith(axios) {
  const context = { module: { exports: {} }, axios };
  vm.runInNewContext(script.replace('export default', 'module.exports ='), context);
  const component = context.module.exports;
  return { component, page: { ...component.data(), ...component.methods, $bvToast: { toast() {} } } };
}

const settled = () => new Promise(resolve => setImmediate(resolve));

test('shop settings loads and saves through the configured API base URL', async () => {
  const calls = [];
  const axios = {
    get(url) { calls.push(url); return Promise.resolve({ data: {
      shop_name: 'CIMS Shop', shop_warehouse_id: 3, shop_enabled: false,
      warehouses: [{ id: 3, code: 'MAIN', name: 'Main Store', site_type: 'main_store' }], url: '/shop',
    } }); },
    put(url) { calls.push(url); return Promise.resolve({ data: {} }); },
  };
  const { component, page } = componentWith(axios);
  page.load();
  await settled();
  assert.equal(buildFullPath('/api/', calls[0]), '/api/shop/settings');
  assert.equal(page.loading, false);
  assert.equal(page.loadError, false);
  assert.equal(page.form.shop_name, 'CIMS Shop');
  assert.equal(component.computed.warehouseOptions.call(page)[1].text, 'MAIN · Main Store (main store)');
  page.save();
  await settled();
  assert.equal(buildFullPath('/api/', calls[1]), '/api/shop/settings');
});

test('an unexpected response displays a recoverable error without crashing the dropdown', async () => {
  const { component, page } = componentWith({ get: () => Promise.resolve({ data: '<html>wrong page</html>' }) });
  page.load();
  await settled();
  assert.equal(page.loading, false);
  assert.equal(page.loadError, true);
  assert.match(page.error, /Unexpected shop settings response/);
  assert.equal(component.computed.warehouseOptions.call(page).length, 1);
});
