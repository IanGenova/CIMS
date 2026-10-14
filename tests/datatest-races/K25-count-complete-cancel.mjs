// K25 — state transition outside the lock: a stock count completed and cancelled at once.
// Each round product 77 at Main set to 10, a count of its category created and
// the product 77 line counted at 4; then test_stockman completes it on A while
// dt_owner cancels it on B.
// Rule (L-states): a count ends either completed (variance applied) or
// cancelled (stock untouched) -- complete and cancel cannot both succeed, and a
// cancelled count never has its variance applied.
import { A, B, rounds, setQte, qte, num, one, today, api, race, short, verdict } from './lib.mjs';

const N = rounds(20);
const cat = num('SELECT category_id FROM products WHERE id=77');
let bad = 0, both = 0, applied = 0;
for (let i = 1; i <= N; i++) {
  setQte(77, 1, 10);
  const c = await api(A, 'dt_owner', 'POST', '/stock_counts', { date: today(), warehouse_id: 1, category_id: cat, notes: 'K25 race' });
  if (c.status !== 200) { console.log(`#${i} setup count failed: ${short(c)}`); process.exit(2); }
  const cid = num('SELECT MAX(id) FROM stock_counts');
  const lid = num(`SELECT id FROM stock_count_details WHERE stock_count_id=${cid} AND product_id=77 LIMIT 1`);
  const u = await api(A, 'dt_owner', 'PUT', `/stock_counts/${cid}`, { details: [{ id: lid, counted_qte: 4 }] });
  if (u.status !== 200) { console.log(`#${i} setup counted line failed: ${short(u)}`); process.exit(2); }
  const [ra, rb] = await race([A, 'test_stockman', 'POST', `/stock_counts/${cid}/complete`], [B, 'dt_owner', 'POST', `/stock_counts/${cid}/cancel`]);
  const shelf = qte(77, 1);
  const row = one(`SELECT CONCAT_WS(' ',status,IFNULL(completed_at,'-')) FROM stock_counts WHERE id=${cid}`);
  const status = row.split(' ')[0];
  const bothOk = ra.status === 200 && rb.status === 200;
  const cancelledApplied = status === 'cancelled' && (shelf !== 10 || !row.endsWith(' -'));
  if (bothOk) both++;
  if (cancelledApplied) applied++;
  if (bothOk || cancelledApplied) bad++;
  console.log(`#${i} count=${cid} A(complete)=${ra.status} B(cancel)=${rb.status} shelf=${shelf} row=[${row}]` +
    `${bothOk ? ' BOTH_SUCCEEDED' : ''}${cancelledApplied ? ' CANCELLED_BUT_APPLIED' : ''}`);
}
verdict('K25', bad, N, 'a count is completed or cancelled, never both', `both answered 200 in ${both}, cancelled-but-applied in ${applied}`);
