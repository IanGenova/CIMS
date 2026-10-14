<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K16 — the Senior/PWD discount is granted on the type alone: no ID number and
 * no holder name are required (M2: the discount needs both).
 */
class K16StatutoryIdRequiredTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function assertNoDiscountGranted($response, $before, $what)
    {
        $sale = DB::table('sales')->where('id', '>', $before)->first();
        $this->assertFalse($sale && (float) $sale->statutory_discount > 0,
            "K16: $what was granted a statutory discount of " . ($sale->statutory_discount ?? '') . ' (sale total '
            . ($sale->GrandTotal ?? '') . ', HTTP ' . $response->status() . ')');
        $response->assertStatus(422);
    }

    /**
     * K16 — a POS senior sale with null ID number and null holder name gets the full 20% discount.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_pos_refuses_a_senior_discount_without_id_and_holder()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->posUnpaid([$this->line(1)], null,
            $this->senior(['statutory_id_number' => null, 'statutory_holder_name' => null]));

        $this->assertNoDiscountGranted($response, $before, 'a senior sale with no ID number and no holder name');
    }

    /**
     * K16 — a POS PWD sale whose ID number and holder name are only spaces gets the discount.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_pos_refuses_a_pwd_discount_with_blank_id_and_holder()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->posUnpaid([$this->line(1)], null,
            $this->senior(['statutory_type' => 'pwd', 'statutory_id_number' => '   ', 'statutory_holder_name' => '   ']));

        $this->assertNoDiscountGranted($response, $before, 'a PWD sale with a blank ID number and holder name');
    }

    /**
     * K16 — a back-office sale (POST /api/sales) with statutory_type pwd and no ID fields at all gets the discount.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_back_office_sale_refuses_a_pwd_discount_without_id_and_holder()
    {
        $before = (int) $this->lastId('sales');

        $response = $this->as($this->owner)->storeSale([$this->line(1)], ['statutory_type' => 'pwd']);

        $this->assertNoDiscountGranted($response, $before, 'a back-office PWD sale with no ID fields');
    }
}
