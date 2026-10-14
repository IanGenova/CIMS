<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W5Helpers;

/**
 * K93 (partial) — Sale returns and purchase returns share Ref numbers: both
 * counters use the prefix RT_ and both start at 1111
 * (DocumentSequence::next('sale_returns'|'purchase_returns', 'RT_', 0, 1111)),
 * so the first sale return and the first purchase return are both RT_1111 and
 * a reference no longer identifies one document.
 *
 * Only the shared Ref is pinned. The payment-reference part of K93 (INV/SL_
 * numbers from an independent counter that look like a sale number) was found
 * by falsify-G7 to be misleading but not wrong, and is not tested here.
 */
class K93ReturnRefsTest extends DatatestCase
{
    use W5Helpers;

    /**
     * K93 — A sale return and a purchase return never carry the same Ref.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_sale_and_purchase_returns_never_share_a_reference()
    {
        $this->actAs($this->owner);
        $this->pos([$this->line(1)])->assertOk();          // a return names a sale it comes from (DATATEST K5)
        $this->saleReturn([$this->line(1)])->assertOk();
        $this->purchaseReturn([$this->buyLine(1)])->assertOk();

        $saleRef = DB::table('sale_returns')->orderByDesc('id')->value('Ref');
        $purchaseRef = DB::table('purchase_returns')->orderByDesc('id')->value('Ref');

        $this->assertNotEquals($saleRef, $purchaseRef,
            "a reference must identify one document: sale return $saleRef vs purchase return $purchaseRef");
    }
}
