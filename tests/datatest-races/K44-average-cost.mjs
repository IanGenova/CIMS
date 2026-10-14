// K44 — moving average cost on simultaneous receipts.
// Each round product 83: cost 100, Main 10, Branch 0. dt_owner receives 10 @ 200
// into Main on A while receiving 10 @ 400 into Branch on B.
// Rule (moving average): cost = (10*100 + 10*200 + 10*400) / 30 = 233.3333,
// whichever order the receipts land in (sequentially it is).
import { A, B, rounds, sql, num, setQte, qte, race, purchaseBody, short, verdict } from './lib.mjs';

const N = rounds(20);
let bad = 0;
for (let i = 1; i <= N; i++) {
  sql('UPDATE products SET cost=100 WHERE id=83');
  setQte(83, 1, 10);
  setQte(83, 2, 0);
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/purchases', purchaseBody(1, 83, 13, 10, 200, 'received')],
    [B, 'dt_owner', 'POST', '/purchases', purchaseBody(2, 83, 13, 10, 400, 'received')]);
  const cost = num('SELECT cost+0 FROM products WHERE id=83');
  const both = ra.status === 200 && rb.status === 200;
  const broken = both && Math.abs(cost - 700 / 3) > 0.001;
  if (broken) bad++;
  console.log(`#${i} A=${ra.status} B=${rb.status} cost=${cost} on_hand=${qte(83, 1)}/${qte(83, 2)} expected=233.3333${broken ? ' WRONG_AVERAGE' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K44', bad, N, 'moving average cost = 233.3333 after both receipts');
