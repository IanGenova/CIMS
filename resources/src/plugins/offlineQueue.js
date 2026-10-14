/*
 * Offline queue for the point of sale.
 *
 * A till that loses its connection mid-shift should still be able to sell.
 * Sales are held in IndexedDB and replayed when the network returns.
 *
 * The whole risk here is double-banking: the browser sends a sale, the reply
 * is lost, the till retries, and the customer is charged twice. Every queued
 * sale therefore carries an idempotency key generated once at capture and
 * reused on every retry. The server records the key and returns the original
 * outcome for a repeat, so a retry can never create a second sale.
 */

const DB_NAME = 'cims-offline';
const DB_VERSION = 1;
const STORE = 'pending_sales';

function openDb() {
  return new Promise((resolve, reject) => {
    if (!window.indexedDB) {
      reject(new Error('This browser cannot store sales offline.'));
      return;
    }

    const request = window.indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = event => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(STORE)) {
        const store = db.createObjectStore(STORE, { keyPath: 'idempotency_key' });
        store.createIndex('captured_at', 'captured_at');
      }
    };

    request.onsuccess = event => resolve(event.target.result);
    request.onerror = () => reject(request.error);
  });
}

function tx(db, mode) {
  return db.transaction(STORE, mode).objectStore(STORE);
}

/** A key that is unique per captured sale and stable across retries. */
function newKey() {
  if (window.crypto && window.crypto.randomUUID) {
    return window.crypto.randomUUID();
  }
  return 'k-' + Date.now() + '-' + Math.random().toString(16).slice(2, 10);
}

export default {
  isOnline() {
    return typeof navigator.onLine === 'boolean' ? navigator.onLine : true;
  },

  /** Hold a sale until the connection comes back. */
  async enqueue(payload) {
    const db = await openDb();
    const record = {
      idempotency_key: newKey(),
      payload: payload,
      captured_at: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
      captured_time: Date.now(),
      attempts: 0,
      last_error: null
    };

    return new Promise((resolve, reject) => {
      const request = tx(db, 'readwrite').add(record);
      request.onsuccess = () => resolve(record);
      request.onerror = () => reject(request.error);
    });
  },

  async all() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const request = tx(db, 'readonly').getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
  },

  async count() {
    try {
      return (await this.all()).length;
    } catch (e) {
      return 0;
    }
  },

  async remove(key) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const request = tx(db, 'readwrite').delete(key);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  },

  async markFailed(key, error) {
    const db = await openDb();
    const store = tx(db, 'readwrite');
    return new Promise(resolve => {
      const get = store.get(key);
      get.onsuccess = () => {
        const record = get.result;
        if (!record) {
          resolve();
          return;
        }
        record.attempts = (record.attempts || 0) + 1;
        record.last_error = error;
        tx(db, 'readwrite').put(record);
        resolve();
      };
      get.onerror = () => resolve();
    });
  },

  /**
   * Send everything queued.
   *
   * A sale the server accepts, or has already seen, is cleared from the queue.
   * One it rejects outright — no stock, for instance — is cleared too but
   * reported back, because retrying it forever would never succeed and would
   * hide the problem. Anything that merely failed to reach the server stays
   * queued for the next attempt.
   */
  async sync(axiosInstance) {
    const pending = await this.all();
    if (!pending.length) {
      return { attempted: 0, accepted: 0, rejected: 0, duplicates: 0, problems: [] };
    }

    const body = {
      sales: pending.map(p => ({
        idempotency_key: p.idempotency_key,
        payload: p.payload,
        captured_at: p.captured_at
      }))
    };

    const response = await axiosInstance.post('offline/sales/sync', body);
    const results = response.data.results || [];
    const problems = [];

    for (const r of results) {
      if (r.status === 'accepted' || r.status === 'duplicate') {
        await this.remove(r.idempotency_key);
      } else if (r.status === 'rejected') {
        await this.remove(r.idempotency_key);
        problems.push({ key: r.idempotency_key, message: r.message });
      }
    }

    return {
      attempted: pending.length,
      accepted: response.data.accepted || 0,
      rejected: response.data.rejected || 0,
      duplicates: response.data.duplicates || 0,
      problems: problems
    };
  },

  /** Register the POS service worker so the shell survives a dropout. */
  registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }
    return navigator.serviceWorker.register('/pos-sw.js').catch(() => null);
  }
};
