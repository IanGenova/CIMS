/**
 * This device and notices sent while the system is closed (Web Push).
 *
 * The server's half is App\utils\WebPush; the service worker that shows them
 * is public/pos-sw.js. A browser only offers push on a secure page: https, or
 * localhost on the computer running the system. A phone opening the system at
 * http://192.168.x.x gets the in-app pop-ups but cannot get these.
 *
 * Every function takes its browser and http client as arguments so the tests
 * can drive it (tests/js/webPush.test.js).
 */

/** The server key, base64url, as the raw bytes PushManager wants. */
export function keyBytes(key) {
  const b64 = (key + '='.repeat((4 - key.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) {
    out[i] = raw.charCodeAt(i);
  }
  return out;
}

export function pushSupported(win, nav) {
  return !!(win && nav && win.isSecureContext && 'serviceWorker' in nav && win.PushManager && win.Notification);
}

function registration(nav) {
  return nav.serviceWorker.register('/pos-sw.js').then(() => nav.serviceWorker.ready);
}

/**
 * Ask for notices on this device, for whoever is signed in. Safe to call on
 * every load: an existing subscription is re-sent, which also moves a shared
 * device to the person now using it. Resolves true when it is on.
 */
export function subscribeDevice(http, win, nav) {
  if (!pushSupported(win, nav) || win.Notification.permission !== 'granted') {
    return Promise.resolve(false);
  }
  return registration(nav)
    .then(reg => reg.pushManager.getSubscription().then(existing => existing
      || http.get('push/key').then(r => reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes(r.data.key),
      }))))
    .then(sub => {
      const s = sub.toJSON();
      return http.post('push/subscribe', { endpoint: s.endpoint, keys: { p256dh: s.keys.p256dh, auth: s.keys.auth } });
    })
    .then(() => true)
    .catch(() => false);
}

/** On sign-out: this device stops getting the last person's notices. */
export function unsubscribeDevice(http, win, nav) {
  if (!pushSupported(win, nav)) {
    return Promise.resolve();
  }
  return registration(nav)
    .then(reg => reg.pushManager.getSubscription())
    .then(sub => sub
      ? http.post('push/unsubscribe', { endpoint: sub.endpoint }).catch(() => null).then(() => sub.unsubscribe())
      : null)
    .catch(() => null);
}
