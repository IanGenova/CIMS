<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W2Helpers;

/**
 * K62 — quotations save $request->Discount (capital D) while computing with
 * `discount`, and accept negative quantities and totals.
 */
class K62QuotationDiscountTest extends DatatestCase
{
    use W2Helpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetCaches();
    }

    private function quote(array $lines, $discount = 0)
    {
        return $this->as($this->owner)->postJson('/api/quotations', ['client_id' => $this->client,
            'warehouse_id' => $this->main, 'date' => now()->toDateString(), 'statut' => 'pending', 'tax_rate' => 0,
            'discount' => $discount, 'shipping' => 0, 'notes' => null,
            'GrandTotal' => array_sum(array_column($lines, 'subtotal')) - $discount, 'details' => $lines]);
    }

    /**
     * K62 — a quotation with a 50.00 discount totals 215.00 but stores discount NULL.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_quotation_stores_its_discount()
    {
        $this->quote([$this->line(1)], 50)->assertOk();

        $q = DB::table('quotations')->orderByDesc('id')->first();
        $this->assertEquals(215, $this->money($q->GrandTotal), 'fixture: 265 less 50');
        $this->assertEquals(50, $q->discount === null ? null : $this->money($q->discount),
            'K62: the quotation stored discount ' . var_export($q->discount, true) . ' for a 50.00 discount');
    }

    /**
     * K62 — a quotation line of -5 bags is accepted, giving a total of -1,325.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_quotation_refuses_a_negative_quantity()
    {
        $before = (int) $this->lastId('quotations');

        $response = $this->quote([$this->line(-5)]);

        $q = DB::table('quotations')->where('id', '>', $before)->first();
        $this->assertTrue($q === null, 'K62: a quotation for -5 bags was saved with total ' . ($q->GrandTotal ?? '')
            . ' (HTTP ' . $response->status() . ')');
        $response->assertStatus(422);
    }

    /**
     * K62 — a 500.00 discount on a 265.00 quotation is accepted, giving a total of -235.00.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_quotation_total_is_never_negative()
    {
        $before = (int) $this->lastId('quotations');

        $this->quote([$this->line(1)], 500);

        $q = DB::table('quotations')->where('id', '>', $before)->first();
        $this->assertTrue($q === null || (float) $q->GrandTotal >= 0,
            'K62: a quotation was saved with a negative total of ' . ($q->GrandTotal ?? ''));
    }
}
