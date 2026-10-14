// K79 — product edit lost update.
// Each round product 95 set to price 102.96 / cost 78. dt_owner, from two
// screens loaded with those values, saves price 110 on A and cost 80 on B at once.
// The two saves are released a random 0-600 ms apart: two people rarely press
// Save in the same millisecond, and when both requests read the row before
// either writes, Eloquent's dirty-column save happens to keep both changes.
// Rule: either both changes survive (110/80) or the stale save is refused --
// two 200s that leave only one change are a lost update.
//
// Safety: ProductsController@update deletes the product's image files from
// public/images/products, which every database shares. The fixture sets the
// product's image to 'no-image.png' first, which update never deletes.
// Repair (2026-10-06): each screen first loads the product, as the edit
// screen does, and sends back the version it loaded; the server refuses (409)
// a save over a change made after that load.
import { A, B, api, rounds, sql, one, race, short, verdict } from './lib.mjs';

const N = rounds(30);
const P = 95;
sql(`UPDATE products SET image='no-image.png' WHERE id=${P}`);
if (one(`SELECT IFNULL(image,'') FROM products WHERE id=${P}`) !== 'no-image.png') {
  console.log('K79: could not clear the product image; refusing to edit a product that names a real file');
  process.exit(2);
}
const [code, name, cat, brand, alert] = sql(`SELECT code,name,category_id,IFNULL(brand_id,'null'),stock_alert+0 FROM products WHERE id=${P}`)[0];
const form = (price, cost) => ({
  name, code, Type_barcode: 'CODE128', price, cost, category_id: Number(cat), brand_id: brand, unit_id: 11, unit_sale_id: 11, unit_purchase_id: 11,
  TaxNet: 12, tax_group_id: 'null', tax_method: '2', vat_class: 'vatable', stock_alert: Number(alert), is_variant: 'false', has_batches: 'false', note: '',
});
let bad = 0;
for (let i = 1; i <= N; i++) {
  sql(`UPDATE products SET price=102.96, cost=78, image='no-image.png' WHERE id=${P}`);
  // Both screens open the product before either saves.
  const [la, lb] = await Promise.all([api(A, 'dt_owner', 'GET', `/Products/${P}/edit`), api(B, 'dt_owner', 'GET', `/Products/${P}/edit`)]);
  const va = la.json && la.json.product ? la.json.product.version : undefined;
  const vb = lb.json && lb.json.product ? lb.json.product.version : undefined;
  const [ra, rb] = await race([A, 'dt_owner', 'PUT', `/Products/${P}`, { ...form(110, 78), version: va }], [B, 'dt_owner', 'PUT', `/Products/${P}`, { ...form(102.96, 80), version: vb }], { delayB: Math.floor(Math.random() * 600) });
  const [price, cost] = sql(`SELECT price+0, cost+0 FROM products WHERE id=${P}`)[0].map(Number);
  const broken = ra.status === 200 && rb.status === 200 && !(price === 110 && cost === 80);
  if (broken) bad++;
  console.log(`#${i} A(price 110)=${ra.status} B(cost 80)=${rb.status} price/cost=${price}/${cost}${broken ? ' LOST_EDIT' : ''}`);
  if (ra.status !== 200) console.log('  A: ' + short(ra));
  if (rb.status !== 200) console.log('  B: ' + short(rb));
}
verdict('K79', bad, N, 'two accepted edits of different fields both survive');
