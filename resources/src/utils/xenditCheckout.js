/**
 * The cashier's side of a Xendit payment (components/XenditCheckout.vue).
 *
 * The browser starts a checkout and then only ever asks the server how it is
 * going; the server asks Xendit. Nothing here decides that money arrived --
 * a payment exists only once the server has recorded it.
 *
 * Tested by tests/js/xenditCheckout.test.js.
 */

export function isFinal(status) {
  return status === 'paid' || status === 'expired' || status === 'cancelled';
}

/** A request for `amount` on this sale; resolves to the checkout. */
export function startCheckout(http, saleId, amount) {
  return http.post('xendit/checkouts', { sale_id: saleId, amount: amount })
    .then(r => r.data.checkout);
}

/**
 * Ask every `every` ms until the checkout is paid, expired or cancelled.
 * A failed request (a dropped connection) just waits for the next turn.
 * Returns stop(), for when the window is closed.
 */
export function watchCheckout(http, id, onUpdate, options) {
  const every = (options && options.every) || 3000;
  const timers = (options && options.timers) || {
    set: (fn, ms) => setTimeout(fn, ms),
    clear: t => clearTimeout(t),
  };
  let stopped = false;
  let timer = null;

  const tick = () => http.get('xendit/checkouts/' + id)
    .then(r => {
      if (stopped) {
        return;
      }
      const checkout = r.data.checkout;
      onUpdate(checkout);
      if (!isFinal(checkout.status)) {
        timer = timers.set(tick, every);
      }
    }, () => {
      if (!stopped) {
        timer = timers.set(tick, every);
      }
    });

  timer = timers.set(tick, every);

  return function stop() {
    stopped = true;
    timers.clear(timer);
  };
}
