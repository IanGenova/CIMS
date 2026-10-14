// K19 — the offline key is recorded after the sale is banked, so the same
// offline sale synced to both servers at once banks twice.
// Each round a fresh key K; dt_owner sends {sales:[{idempotency_key:K, payload: POS 1 x product 69}]}
// to A and to B at the same moment.
// Rule: one idempotency key banks at most one sale, and a replay is answered
// "duplicate", not a raw 500.
import { A, B, rounds, setQte, num, one, maxId, race, posBody, short, verdict } from './lib.mjs';

const N = rounds(20);
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(69, 1, 50);
  const key = `k19race-${Date.now()}-${i}`;
  const s0 = maxId('sales');
  const body = { sales: [{ idempotency_key: key, payload: posBody(1, 69, 10, 1, 100) }] };
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/offline/sales/sync', body], [B, 'dt_owner', 'POST', '/offline/sales/sync', body]);
  const created = num(`SELECT COUNT(*) FROM sales WHERE id>${s0} AND deleted_at IS NULL`);
  const keys = one(`SELECT CONCAT(COUNT(*),' ',COALESCE(GROUP_CONCAT(CONCAT(IFNULL(sale_id,'-'),':',status)),'')) FROM offline_sale_keys WHERE idempotency_key='${key}'`);
  const crashed = ra.status >= 500 || rb.status >= 500;
  const broken = created > 1 || crashed;
  if (broken) bad++;
  console.log(`#${i} A=${ra.status} B=${rb.status} sales_created=${created} key_rows=${keys} shelf=${num('SELECT qte+0 FROM product_warehouse WHERE product_id=69 AND warehouse_id=1 AND product_variant_id IS NULL AND deleted_at IS NULL')}(from 50)` +
    `${created > 1 ? ' DOUBLE_BANKED' : ''}${crashed ? ' RAW_500' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K19', bad, N, 'one offline key banks at most one sale (and a replay is not a 500)');
