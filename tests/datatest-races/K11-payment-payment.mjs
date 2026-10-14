// K11 — payment checks without a parent lock: two full payments at once.
// Each round an unpaid POS sale (2 x product 65); then dt_owner pays the full
// GrandTotal on server A and again on server B at the same moment.
// Rule M4: the payments on a sale never exceed its total (one full payment only).
import { A, B, rounds, setQte, num, one, today, api, race, posBody, short, verdict } from './lib.mjs';

const N = rounds(20);
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(65, 1, 400);
  const s = await api(A, 'dt_owner', 'POST', '/pos/CreatePOS', posBody(1, 65, 1, 2, 112));
  const sid = s.json && s.json.id;
  if (!sid) { console.log(`#${i} setup failed: ${short(s)}`); process.exit(2); }
  const total = num(`SELECT GrandTotal+0 FROM sales WHERE id=${sid}`);
  const body = { sale_id: sid, date: today(), montant: total, Reglement: 'Cash', change: 0 };
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/payment/sale', body], [B, 'dt_owner', 'POST', '/payment/sale', body]);
  const paid = num(`SELECT COALESCE(SUM(montant),0)+0 FROM payment_sales WHERE sale_id=${sid} AND deleted_at IS NULL`);
  const npay = num(`SELECT COUNT(*) FROM payment_sales WHERE sale_id=${sid} AND deleted_at IS NULL`);
  const row = one(`SELECT CONCAT_WS(' ',Ref,paid_amount+0,payment_statut) FROM sales WHERE id=${sid}`);
  const broken = paid > total + 0.005;
  if (broken) bad++;
  console.log(`#${i} sale=${sid} total=${total} A=${ra.status} B=${rb.status} payments=${npay} sum=${paid} [Ref paid_amount statut]=${row}${broken ? ' OVERPAID' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K11', bad, N, 'M4: sum of payments on a sale <= its GrandTotal');
