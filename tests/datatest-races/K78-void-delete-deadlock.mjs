// K78 — VoidService runs a single-attempt transaction, so a void racing a
// delete of the same sale can deadlock and the deadlock reaches the user as a
// raw 500 with the SQL in it.
// Each round an unpaid POS sale of 2 x product 65; dt_owner voids it on A while
// deleting it on B.
// Rule: neither request answers 5xx (one wins, the other is refused cleanly or
// retried). The deadlock is rare (about 4 in 100 rounds), so the default is
// 150 rounds.
import { A, B, rounds, setQte, one, api, race, posBody, short, verdict } from './lib.mjs';

const N = rounds(150);
let bad = 0;
for (let i = 1; i <= N; i++) {
  setQte(65, 1, 50);
  const s = await api(A, 'dt_owner', 'POST', '/pos/CreatePOS', posBody(1, 65, 1, 2, 112));
  const sid = s.json && s.json.id;
  if (!sid) { console.log(`#${i} setup failed: ${short(s)}`); process.exit(2); }
  const [ra, rb] = await race([A, 'dt_owner', 'POST', `/sales/${sid}/void`, { reason: 'K78 race void' }], [B, 'dt_owner', 'DELETE', `/sales/${sid}`]);
  // Repair (2026-10-06): also broken if both succeed -- a sale voided and then
  // deleted (or the reverse) has its stock put back twice.
  const both = ra.status === 200 && rb.status === 200;
  const broken = ra.status >= 500 || rb.status >= 500 || ra.status === 0 || rb.status === 0 || both;
  if (broken) bad++;
  const row = one(`SELECT CONCAT_WS(' ',statut,IFNULL(voided_at,'-'),IFNULL(deleted_at,'-')) FROM sales WHERE id=${sid}`);
  if (broken || i % 10 === 0) {
    console.log(`#${i} sale=${sid} A(void)=${ra.status} B(delete)=${rb.status} row=[${row}]${broken ? ' RAW_500' : ''}`);
    if (ra.status >= 500) console.log('  A: ' + short(ra));
    if (rb.status >= 500) console.log('  B: ' + short(rb));
  }
}
verdict('K78', bad, N, 'void || delete: never a raw 5xx, and never both');
