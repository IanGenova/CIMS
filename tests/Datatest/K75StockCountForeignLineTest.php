<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K75 — A stock count update with another count's line id returns 200, writes
 * nothing, and moves the count to in_progress.
 */
class K75StockCountForeignLineTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K75 — The Main stockman sends a Branch count's line id into the Main count and gets 200 "success".
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_a_count_update_naming_another_counts_line_is_refused()
    {
        $this->actAs($this->owner)->postJson('/api/stock_counts', ['date' => $this->today(), 'warehouse_id' => $this->branch])->assertOk();
        $branchCount = $this->lastId('stock_counts');
        $this->actAs($this->stockman)->postJson('/api/stock_counts', ['date' => $this->today(), 'warehouse_id' => $this->main])->assertOk();
        $mainCount = $this->lastId('stock_counts');
        $foreignLine = DB::table('stock_count_details')->where('stock_count_id', $branchCount)->value('id');
        $this->assertNotNull($foreignLine, 'fixture: the Branch count has a cement line');

        $response = $this->putJson("/api/stock_counts/{$mainCount}", ['details' => [['id' => $foreignLine, 'counted_qte' => 0]]]);

        $this->assertSame(422, $response->getStatusCode(), 'line ' . $foreignLine . ' belongs to the Branch count and must be named as foreign (got HTTP '
            . $response->getStatusCode() . ': ' . substr((string) $response->getContent(), 0, 200) . ')');
        $this->assertSame('draft', DB::table('stock_counts')->where('id', $mainCount)->value('status'),
            'nothing was counted, so the Main count must stay draft');
    }
}
