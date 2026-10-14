// Installing CIMS as a Windows app (tests/js/installApp.test.js).
//
// With public/manifest.webmanifest, Edge and Chrome can install CIMS: a
// Start-menu and taskbar icon, its own window. The browser offers this to the
// page once, as a "beforeinstallprompt" event; we keep it so the profile menu
// can offer "Install CIMS" whenever the person is ready.

function runningAsApp(win) {
  const standalone = win && win.matchMedia && win.matchMedia('(display-mode: standalone)').matches;
  return !!(standalone || (win && win.navigator && win.navigator.standalone));
}

export function createInstaller(win) {
  let offer = null;
  let listeners = [];

  const state = () => {
    const installed = runningAsApp(win);
    return { canInstall: !!offer && !installed, installed };
  };
  const notify = () => listeners.forEach(fn => fn(state()));

  if (win && win.addEventListener) {
    win.addEventListener('beforeinstallprompt', e => {
      // Keep the offer for our own menu item instead of the browser's mini-bar.
      e.preventDefault();
      offer = e;
      notify();
    });
    win.addEventListener('appinstalled', () => {
      offer = null;
      notify();
    });
  }

  return {
    state,
    onChange(fn) {
      listeners.push(fn);
      return () => {
        listeners = listeners.filter(l => l !== fn);
      };
    },
    // Shows the browser's own install dialog; resolves to what was chosen.
    install() {
      if (!offer) {
        return Promise.resolve('unavailable');
      }
      const e = offer;
      offer = null; // usable once
      notify();
      return Promise.resolve(e.prompt())
        .then(() => e.userChoice)
        .then(choice => (choice && choice.outcome) || 'dismissed');
    }
  };
}

// The worker that already serves the POS offline and the push notices, now
// for the whole signed-in app (the installed window opens on any page).
export function registerAppWorker(nav) {
  if (!nav || !('serviceWorker' in nav)) {
    return Promise.resolve(null);
  }
  return nav.serviceWorker.register('/pos-sw.js').catch(() => null);
}
