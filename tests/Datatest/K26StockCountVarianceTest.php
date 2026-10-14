<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W1Helpers;

/**
 * K26: completing a stock count writes qte = counted_qte instead of applying
 * counted - system at count time, and duplicate open counts of the same scope
 * are allowed. Owner decision: final stock = current stock + (counted - system
 * quantity when that line was counted).
 */
class K26StockCountVarianceTest extends DatatestCase
{
    use W1Helpers;

    private function openCount()
    {
        return $this->postJson('/api/stock_counts', ['date' => $this->today(), 'warehouse_id' => $this->main,
            'category_id' => DB::table('products')->where('id', $this->cement)->value('category_id')]);
    }

    /**
     * K26 — sales made during a count are undone when it completes (absolute write).
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_completing_a_count_applies_the_variance_found_not_the_counted_figure()
    {
        $countId = $this->as($this->stockman)->openCount()->assertOk()->json('id');
        $line = DB::table('stock_count_details')->where('stock_count_id', $countId)->where('product_id', $this->cement)->first();
        $this->assertEquals(50, (float) $line->system_qte);
        // 40 on the shelf when counted: 10 missing.
        $this->putJson("/api/stock_counts/{$countId}", ['details' => [['id' => $line->id, 'counted_qte' => 40]]])->assertOk();

        // 5 bags sold before the count is completed.
        $this->as($this->owner)->pos([$this->line(5)])->assertOk();
        $this->assertEquals(45, $this->shelf());

        $this->as($this->stockman)->postJson("/api/stock_counts/{$countId}/complete")->assertOk();

        $this->assertEquals(35, $this->shelf(), '45 now + (40 counted - 50 system) = 35; the 5 sold stay sold');
    }

    /**
     * K26 — a second open count of the same warehouse and category is accepted.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_second_open_count_of_the_same_scope_is_refused()
    {
        $this->as($this->stockman)->openCount()->assertOk();

        $second = $this->openCount();

        $this->assertRefused($second, 'Main / Cement is already being counted');
        $this->assertSame(1, DB::table('stock_counts')->whereIn('status', ['draft', 'in_progress'])->whereNull('deleted_at')->count());
    }
}
