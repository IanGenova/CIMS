// K11 — credit check without a parent lock: two on-account sales over the limit.
// Each round a new client with credit_limit 300; dt_owner sends the same
// completed, unpaid sale (GrandTotal 250.88 after VAT) to A and B at once.
// Rule M5: a customer's balance due never goes over their credit limit
// (the second sale alone is refused when sent after the first).
import { A, B, rounds, setQte, sql, one, num, today, race, short, verdict } from './lib.mjs';

const N = rounds(20);
const tag = Date.now().toString(36);
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(65, 1, 400);
  const name = `K11 race ${tag} ${i}`;
  sql(`INSERT INTO clients (name,code,email,country,city,phone,adresse,is_tax_exempt,credit_limit,opening_balance,created_at,updated_at)
       SELECT '${name}',MAX(code)+1,'','PH','Cebu','','',0,300,0,NOW(),NOW() FROM clients`);
  const cid = num(`SELECT MAX(id) FROM clients WHERE name='${name}'`);
  const body = { client_id: cid, warehouse_id: 1, date: today(), statut: 'completed', GrandTotal: 224, amount: 0, tax_rate: 0, discount: 0, shipping: 0,
    payment: { status: 'unpaid', Reglement: 'Cash' },
    details: [{ product_id: 65, product_variant_id: null, sale_unit_id: 1, purchase_unit_id: 1, quantity: 2, Unit_price: 112, Unit_cost: 112, subtotal: 224,
      Net_cost: 112, discount: 0, discount_Method: '2', tax_percent: 0, tax_method: '1', name: 'p65', no_unit: 1 }] };
  const [ra, rb] = await race([A, 'dt_owner', 'POST', '/sales', body], [B, 'dt_owner', 'POST', '/sales', body]);
  const owed = num(`SELECT COALESCE(SUM(GrandTotal-paid_amount),0)+0 FROM sales WHERE client_id=${cid} AND deleted_at IS NULL`);
  const nsales = one(`SELECT COUNT(*) FROM sales WHERE client_id=${cid} AND deleted_at IS NULL`);
  const broken = owed > 300 + 0.005;
  if (broken) bad++;
  console.log(`#${i} client=${cid} limit=300 A=${ra.status} B=${rb.status} sales=${nsales} owed=${owed}${broken ? ' OVER_LIMIT' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K11', bad, N, 'M5: balance due <= credit limit (300)');
