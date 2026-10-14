/**
 * Installing CIMS as a Windows app.
 *
 * Edge and Chrome offer to install a site that has a manifest
 * (public/manifest.webmanifest): it gets a Start-menu and taskbar icon and its
 * own window. The browser hands the page that offer once ("beforeinstallprompt");
 * this keeps it so the profile menu can show "Install CIMS" and use it later,
 * and hides the item once CIMS is installed or already running as the app.
 *
 *   node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');

const src = readFileSync(join(__dirname, '../../resources/src/utils/installApp.js'), 'utf8');
const { createInstaller, registerAppWorker } = new Function(
  src.replace(/export function/g, 'function') + '\nreturn { createInstaller, registerAppWorker };')();

function fakeWindow({ standalone = false } = {}) {
  const handlers = {};
  return {
    handlers,
    addEventListener(name, fn) { (handlers[name] = handlers[name] || []).push(fn); },
    fire(name, event) { (handlers[name] || []).forEach(fn => fn(event)); },
    matchMedia: q => ({ matches: standalone && q === '(display-mode: standalone)' }),
    navigator: {},
  };
}

function offer(outcome = 'accepted') {
  const e = { prevented: false, prompted: 0 };
  e.preventDefault = () => { e.prevented = true; };
  e.prompt = () => { e.prompted += 1; return Promise.resolve(); };
  e.userChoice = Promise.resolve({ outcome });
  return e;
}

test('nothing to offer until the browser says CIMS can be installed', () => {
  const installer = createInstaller(fakeWindow());
  assert.deepStrictEqual(installer.state(), { canInstall: false, installed: false });
});

test('the browser offer is kept, its own mini-bar suppressed, and listeners told', () => {
  const win = fakeWindow();
  const installer = createInstaller(win);
  const seen = [];
  installer.onChange(s => seen.push(s.canInstall));
  const e = offer();
  win.fire('beforeinstallprompt', e);
  assert.strictEqual(e.prevented, true);
  assert.deepStrictEqual(installer.state(), { canInstall: true, installed: false });
  assert.deepStrictEqual(seen, [true]);
});

test('Install shows the browser dialog once and reports what the person chose', async () => {
  const win = fakeWindow();
  const installer = createInstaller(win);
  const e = offer('dismissed');
  win.fire('beforeinstallprompt', e);
  assert.strictEqual(await installer.install(), 'dismissed');
  assert.strictEqual(e.prompted, 1);
  // An offer can only be used once; the browser sends a new one if it allows.
  assert.strictEqual(installer.state().canInstall, false);
  assert.strictEqual(await installer.install(), 'unavailable');
});

test('once installed, or when already running as the app, there is nothing to offer', () => {
  const win = fakeWindow();
  const installer = createInstaller(win);
  win.fire('beforeinstallprompt', offer());
  win.fire('appinstalled', {});
  assert.strictEqual(installer.state().canInstall, false);

  const app = fakeWindow({ standalone: true });
  const inApp = createInstaller(app);
  app.fire('beforeinstallprompt', offer());
  assert.deepStrictEqual(inApp.state(), { canInstall: false, installed: true });
});

test('a listener can stop listening', () => {
  const win = fakeWindow();
  const installer = createInstaller(win);
  let calls = 0;
  const stop = installer.onChange(() => { calls += 1; });
  stop();
  win.fire('beforeinstallprompt', offer());
  assert.strictEqual(calls, 0);
});

test('the app-wide worker is the existing one at the site root, and a browser without workers is fine', async () => {
  const registered = [];
  const nav = { serviceWorker: { register: (url) => { registered.push(url); return Promise.resolve('reg'); } } };
  assert.strictEqual(await registerAppWorker(nav), 'reg');
  assert.deepStrictEqual(registered, ['/pos-sw.js']);
  assert.strictEqual(await registerAppWorker({}), null);
  const failing = { serviceWorker: { register: () => Promise.reject(new Error('insecure')) } };
  assert.strictEqual(await registerAppWorker(failing), null);
});
