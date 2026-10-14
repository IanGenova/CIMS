// K2 — no row lock on product_warehouse writes: two adjustments at once.
// Product 54 at Main set to 5; dt_owner (server A) and test_stockman (server B)
// each subtract 1. Rule S2: the shelf must end at 5 minus one per accepted
// adjustment, matching the movements written.
import { A, B, rounds, setQte, qte, maxId, movesSince, today, race, short, verdict } from './lib.mjs';

const N = rounds(20);
const P = 54;
const body = { warehouse_id: 1, date: today(), notes: 'K2 race', details: [{ product_id: P, product_variant_id: null, quantity: 1, type: 'sub' }] };
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(P, 1, 5);
  const m0 = maxId('stock_movements');
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/adjustments', body], [B, 'test_stockman', 'POST', '/adjustments', body]);
  const q = qte(P, 1);
  const mv = movesSince(P, 1, m0);
  const expected = 5 - (ra.status === 200) - (rb.status === 200);
  const broken = q !== expected || q - 5 !== mv;
  if (broken) bad++;
  console.log(`#${i} A=${ra.status} B=${rb.status} shelf=${q} expected=${expected} movements=${mv}${broken ? ' LOST_UPDATE' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K2', bad, N, 'S2: shelf = 5 - accepted adjustments = 5 + sum(movements)');
