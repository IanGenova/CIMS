/*
 * Service worker for the point of sale.
 *
 * Its job is narrow on purpose: keep the POS shell and its assets loadable
 * when the connection drops, so a till that loses the network can still ring
 * up a sale. It deliberately does NOT cache or replay API responses --
 * inventory figures go stale in seconds, and serving a cached stock level
 * would be worse than showing nothing.
 *
 * The sales themselves are queued in IndexedDB by the page, not here, because
 * they need the page's idempotency key and its user session.
 *
 * WHY SCRIPTS AND STYLES ARE NOT CACHED FIRST
 *
 * They were, on the stated grounds that bundle files are content-hashed and so
 * can never change under a given name. That is not true of this build:
 * mix-manifest.json maps every file to itself -- `/js/main.min.js` is always
 * `/js/main.min.js` -- while its contents are rewritten by every build.
 *
 * Serving those cache-first pinned each browser to whichever build it happened
 * to see first, permanently. A fix could be deployed and the tills would go on
 * running the old code, with nothing on screen to say so; a half-updated pairing
 * of shell and chunks just renders a blank page. So anything whose contents can
 * change under a fixed name is fetched from the network first and only falls
 * back to the cache when the network is genuinely unavailable. That is the whole
 * point of the cache here -- to survive a dropped connection, not to avoid a
 * request on a healthy one.
 *
 * Fonts and images keep cache-first: they are large, they are not rewritten by
 * a build, and a stale one is a cosmetic problem rather than a broken till.
 */

// Bumping this drops everything cached by an earlier version on activate,
// which is what releases a browser stuck on a previous build.
const CACHE = 'cims-pos-v2';

// Only the shell. Everything else is added as it is fetched.
const SHELL = [
  '/',
  '/css/master.css',
  '/js/main.min.js',
];

// Rewritten by a build, so never trusted from cache while online.
const MUTABLE = /\.(js|css)$/i;

// Not rewritten by a build, and cheap to keep.
const IMMUTABLE = /\.(png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i;

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      .then(cache => cache.addAll(SHELL).catch(() => {
        // A missing asset must not stop the worker installing.
      }))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/** Fresh if the network can be reached, last known good if it cannot. */
function networkFirst(request) {
  return fetch(request)
    .then(response => {
      if (response && response.status === 200) {
        const copy = response.clone();
        caches.open(CACHE).then(cache => cache.put(request, copy));
      }
      return response;
    })
    .catch(() => caches.match(request).then(hit => hit || Response.error()));
}

/** Straight from the cache when it is there; the network is the fallback. */
function cacheFirst(request) {
  return caches.match(request).then(hit => {
    if (hit) {
      return hit;
    }
    return fetch(request).then(response => {
      if (response && response.status === 200) {
        const copy = response.clone();
        caches.open(CACHE).then(cache => cache.put(request, copy));
      }
      return response;
    });
  });
}

self.addEventListener('fetch', event => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  // Never serve application data from cache: a stale stock figure is worse
  // than an honest failure.
  if (url.pathname.startsWith('/api/')) {
    return;
  }

  if (MUTABLE.test(url.pathname)) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (IMMUTABLE.test(url.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Navigation: try the network, fall back to the cached shell so the app
  // still boots and can show its offline state.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/').then(hit => hit || Response.error()))
    );
  }
});

/*
 * Notices pushed by the server (App\utils\WebPush): these reach a phone, or a
 * computer whose browser is closed. When the system is open and on screen, the
 * bell's own pop-up shows it, so the page is only told to look now.
 */
self.addEventListener('push', event => {
  let n = {};
  try {
    n = event.data ? event.data.json() : {};
  } catch (e) {
    n = {};
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windows => {
      const onScreen = windows.filter(w => w.visibilityState === 'visible');
      if (onScreen.length) {
        onScreen.forEach(w => w.postMessage({ type: 'cims-notification', id: n.id }));
        return null;
      }
      return self.registration.showNotification(n.title || 'CIMS', {
        body: n.body || '',
        // The bell uses the same tag, so a notice never shows twice.
        tag: 'cims-' + n.id,
        icon: '/images/favicon.ico',
        badge: '/images/favicon.ico',
        requireInteraction: n.level === 'danger',
        data: { link: n.link },
      });
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const wanted = (event.notification.data || {}).link;
  // Only a page inside the system; a notice never sends anyone elsewhere.
  const link = typeof wanted === 'string' && /^\/(?!\/)/.test(wanted) ? wanted : '/app/dashboard';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windows => {
      const tab = windows.find(w => w.url && w.url.indexOf(self.location.origin) === 0);
      if (tab) {
        return tab.focus().then(t => (t || tab).navigate(link));
      }
      return self.clients.openWindow(link);
    })
  );
});
