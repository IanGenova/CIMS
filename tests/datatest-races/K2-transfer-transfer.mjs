// K2 — no row lock on product_warehouse writes: two transfers at once.
// Product 58: Main 3, Branch 0. dt_owner (A) and test_stockman (B) each move 2
// Main->Branch, completed. Rule S1/S2: at most one can succeed (3 on the shelf),
// and each shelf must equal its start plus its movements.
import { A, B, rounds, setQte, qte, maxId, movesSince, today, race, short, verdict } from './lib.mjs';

const N = rounds(20);
const P = 58;
const body = {
  transfer: { from_warehouse: 1, to_warehouse: 2, date: today(), statut: 'completed', notes: 'K2 race', tax_rate: 0, discount: 0, shipping: 0 },
  details: [{ product_id: P, product_variant_id: null, sale_unit_id: 10, purchase_unit_id: 10, quantity: 2, Unit_price: 10, Unit_cost: 10,
    subtotal: 20, Net_cost: 10, discount: 0, discount_Method: '2', tax_percent: 0, tax_method: '1', name: 'p58' }],
};
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(P, 1, 3);
  setQte(P, 2, 0);
  const m0 = maxId('stock_movements');
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/transfers', body], [B, 'test_stockman', 'POST', '/transfers', body]);
  const main = qte(P, 1), branch = qte(P, 2);
  const mvMain = movesSince(P, 1, m0), mvBranch = movesSince(P, 2, m0);
  const both = ra.status === 200 && rb.status === 200;
  const drift = main !== 3 + mvMain || branch !== mvBranch;
  const broken = both || drift || main < 0;
  if (broken) bad++;
  console.log(`#${i} A=${ra.status} B=${rb.status} main=${main} branch=${branch} movements=${mvMain}/${mvBranch}` +
    `${both ? ' BOTH_ACCEPTED(4 out of 3)' : ''}${drift ? ' LOST_UPDATE' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K2', bad, N, 'S1/S2: two transfers of 2 from a shelf of 3 cannot both succeed; shelf = start + movements');
