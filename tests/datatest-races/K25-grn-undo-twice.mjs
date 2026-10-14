// K25 — state transition outside the lock: the same goods receipt undone twice.
// Each round product 87 at Main set to 10, a purchase ordered (5 @ 210), a
// goods receipt of 5 (shelf 15); then dt_owner sends DELETE /goods_receipts/{id}
// to A and B at once.
// Rule L3/S2: a receipt is undone once, so the shelf returns to 10 (one -5 movement).
import { A, B, rounds, setQte, qte, num, maxId, movesSince, today, api, race, purchaseBody, short, verdict } from './lib.mjs';

const N = rounds(20);
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(87, 1, 10);
  const p = await api(A, 'dt_owner', 'POST', '/purchases', purchaseBody(1, 87, 13, 5, 210, 'ordered'));
  if (p.status !== 200) { console.log(`#${i} setup purchase failed: ${short(p)}`); process.exit(2); }
  const pid = num('SELECT MAX(id) FROM purchases');
  const did = num(`SELECT id FROM purchase_details WHERE purchase_id=${pid}`);
  const g = await api(A, 'dt_owner', 'POST', '/goods_receipts', { purchase_id: pid, date: today(), details: [{ purchase_detail_id: did, received_qte: 5, rejected_qte: 0 }] });
  if (g.status !== 200) { console.log(`#${i} setup receipt failed: ${short(g)}`); process.exit(2); }
  const gid = num('SELECT MAX(id) FROM goods_receipts');
  const afterReceipt = qte(87, 1);
  const m0 = maxId('stock_movements');
  const [ra, rb] = await race([A, 'dt_owner', 'DELETE', `/goods_receipts/${gid}`], [B, 'dt_owner', 'DELETE', `/goods_receipts/${gid}`]);
  const shelf = qte(87, 1);
  const mv = movesSince(87, 1, m0);
  const broken = shelf !== 10 || mv !== -5;
  if (broken) bad++;
  console.log(`#${i} grn=${gid} shelf_after_receipt=${afterReceipt} A=${ra.status} B=${rb.status} shelf=${shelf} undo_movements=${mv}${broken ? ' DOUBLE_UNDO' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K25', bad, N, 'a goods receipt is undone once (shelf back to 10, one -5 movement)');
