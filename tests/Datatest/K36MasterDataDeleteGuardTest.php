<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K36 — Master-data deletes have no in-use guard. Decision: customers,
 * suppliers, warehouses and products with history or balances are archived
 * (hidden, kept), not deleted; a currency or unit in use cannot be deleted.
 */
class K36MasterDataDeleteGuardTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K36 — Deleting a product with 70 bags on hand soft-deletes its stock rows and no movement is written.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_product_with_stock_does_not_lose_the_stock()
    {
        // Never let the delete path near a real file in public/images/products.
        DB::table('products')->where('id', $this->cement)->update(['image' => 'no-image.png']);
        $this->actAs($this->owner)->deleteJson("/api/Products/{$this->cement}");

        $kept = DB::table('product_warehouse')->where('product_id', $this->cement)->whereNull('deleted_at')->sum('qte');
        $this->assertEquals(70, (float) $kept,
            'refused or archived, the 50 + 20 bags on hand must still be counted; they vanished with no movement');
    }

    /**
     * K36 — A currency used by a sale can be deleted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_currency_used_by_a_sale_cannot_be_deleted()
    {
        $usd = $this->currency('USD', 58);
        $this->actAs($this->owner)->sale([$this->line(1)], ['currency_id' => $usd])->assertOk();
        $this->assertSame($usd, (int) DB::table('sales')->orderByDesc('id')->value('currency_id'), 'fixture: the sale is in USD');

        $response = $this->deleteJson("/api/currencies/{$usd}");

        $this->assertRefused($response, 'map: a currency still in use cannot be deleted');
        $this->assertNull(DB::table('currencies')->where('id', $usd)->value('deleted_at'));
    }

    /**
     * K36 — A unit used by live products can be deleted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_unit_used_by_products_cannot_be_deleted()
    {
        $piece = DB::table('units')->insertGetId(['name' => 'Piece', 'ShortName' => 'pc', 'operator' => '*', 'operator_value' => 1]);
        $this->productRow(['unit_id' => $piece, 'unit_sale_id' => $piece, 'unit_purchase_id' => $piece, 'name' => 'Hinge'], 5, 0);

        $response = $this->actAs($this->owner)->deleteJson("/api/units/{$piece}");

        $this->assertRefused($response, 'the Hinge product is sold by the piece; the unit is in use');
        $this->assertNull(DB::table('units')->where('id', $piece)->value('deleted_at'));
    }

    /**
     * K36 — A customer with an open balance can be deleted and the receivable disappears.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_customer_with_an_open_balance_is_refused_or_archived_with_the_balance_kept()
    {
        $this->actAs($this->owner);
        $this->unpaidSale(); // 530 owed

        $response = $this->deleteJson("/api/clients/{$this->client}");

        $refused = $response->getStatusCode() >= 400 && $response->getStatusCode() < 500
            && DB::table('clients')->where('id', $this->client)->value('deleted_at') === null;
        if ($refused) {
            $this->addToAssertionCount(1); // refused: the customer and the 530 owed are untouched

            return;
        }
        $report = $this->getJson("/api/report/client/{$this->client}");
        $this->assertSame(200, $report->getStatusCode(),
            'archived, the customer and the 530 owed must still be reportable (got HTTP ' . $report->getStatusCode() . ')');
        $this->assertEquals(530, (float) $report->json('report.due'));
    }

    /**
     * K36 — A warehouse with no stock but an open purchase order can be deleted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_warehouse_with_an_open_document_cannot_be_deleted()
    {
        $annex = DB::table('warehouses')->insertGetId(['name' => 'Annex']);
        $this->actAs($this->owner)->purchase([$this->buyLine(10)], ['warehouse_id' => $annex, 'statut' => 'ordered'])->assertOk();
        $this->assertSame(1, DB::table('purchases')->where('warehouse_id', $annex)->count(), 'fixture: an open order for Annex');

        $response = $this->deleteJson("/api/warehouses/{$annex}");

        $this->assertRefused($response, 'the open purchase order still points at Annex');
        $this->assertNull(DB::table('warehouses')->where('id', $annex)->value('deleted_at'));
    }
}
