// K12 — BatchService::allocate re-reads the lot without the lock: two sales from one lot.
// Lot 34 of product 112 (batch_stock id 36) and the Main shelf both set to 10;
// dt_owner sells 2 on server A and 2 on server B at once.
// Rule S3: lot qte = 10 - 2 per accepted sale, equal to the warehouse total and
// to 10 + sum(batch movements).
import { A, B, rounds, sql, num, setQte, qte, maxId, race, posBody, short, verdict } from './lib.mjs';

const N = rounds(20);
const body = posBody(1, 112, 15, 2, 100);
let bad = 0;
for (let i = 1; i <= N; i++) {
  sql('UPDATE batch_stock SET qte=10 WHERE id=36');
  setQte(112, 1, 10);
  const bm0 = maxId('batch_movements');
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/pos/CreatePOS', body], [B, 'dt_owner', 'POST', '/pos/CreatePOS', body]);
  const lot = num('SELECT qte+0 FROM batch_stock WHERE id=36');
  const shelf = qte(112, 1);
  const moves = num(`SELECT COALESCE(SUM(qte),0)+0 FROM batch_movements WHERE product_batch_id=34 AND warehouse_id=1 AND id>${bm0}`);
  const expected = 10 - 2 * (ra.status === 200) - 2 * (rb.status === 200);
  const broken = lot !== expected || shelf !== expected || lot !== 10 + moves;
  if (broken) bad++;
  console.log(`#${i} A=${ra.status} B=${rb.status} lot=${lot} shelf=${shelf} expected=${expected} lot_movements=${moves}${broken ? ' LOST_UPDATE' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K12', bad, N, 'S3: lot = shelf = 10 - sold = 10 + sum(lot movements)');
