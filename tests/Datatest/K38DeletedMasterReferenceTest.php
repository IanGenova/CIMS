<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K38 — New documents accept soft-deleted or nonexistent master records
 * (customer, supplier, product, currency).
 */
class K38DeletedMasterReferenceTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K38 — A sale is accepted for a deleted customer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_for_a_deleted_customer_is_refused()
    {
        DB::table('clients')->where('id', $this->client)->update(['deleted_at' => now()]);

        $response = $this->actAs($this->owner)->sale([$this->line(1)]);

        $this->assertRefused($response, 'the customer has been deleted');
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertEquals(50, $this->shelf($this->main));
    }

    /**
     * K38 — A POS sale is accepted for a deleted customer.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_pos_sale_for_a_deleted_customer_is_refused()
    {
        DB::table('clients')->where('id', $this->client)->update(['deleted_at' => now()]);

        $response = $this->actAs($this->owner)->pos([$this->line(1)]);

        $this->assertRefused($response, 'the customer has been deleted');
        $this->assertSame(0, DB::table('sales')->count());
    }

    /**
     * K38 — A purchase is accepted from a deleted supplier.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_from_a_deleted_supplier_is_refused()
    {
        DB::table('providers')->where('id', $this->supplier)->update(['deleted_at' => now()]);

        $response = $this->actAs($this->owner)->purchase([$this->buyLine(10)]);

        $this->assertRefused($response, 'the supplier has been deleted');
        $this->assertSame(0, DB::table('purchases')->count());
    }

    /**
     * K38 — A purchase line is accepted for a deleted product.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_purchase_of_a_deleted_product_is_refused()
    {
        $gone = $this->productRow(['deleted_at' => now(), 'name' => 'Discontinued grout']);

        $response = $this->actAs($this->owner)->purchase([$this->buyLine(10, 100, ['product_id' => $gone])]);

        $this->assertRefused($response, 'the product has been deleted');
        $this->assertSame(0, DB::table('purchase_details')->where('product_id', $gone)->count());
    }

    /**
     * K38 — A sale in a deleted currency is accepted and priced at rate 1.0.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_in_a_deleted_currency_is_refused()
    {
        $usd = $this->currency('USD', 58, ['deleted_at' => now()]);

        $response = $this->actAs($this->owner)->sale([$this->line(1)], ['currency_id' => $usd]);

        $this->assertRefused($response, 'the currency has been deleted; it must not fall back to rate 1.0');
        $this->assertSame(0, DB::table('sales')->count());
    }

    /**
     * K38 — A sale in a currency that does not exist is accepted at rate 1.0.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_sale_in_a_nonexistent_currency_is_refused()
    {
        $response = $this->actAs($this->owner)->sale([$this->line(1)], ['currency_id' => 999999]);

        $this->assertRefused($response, 'currency 999999 does not exist');
        $this->assertSame(0, DB::table('sales')->count());
    }
}
