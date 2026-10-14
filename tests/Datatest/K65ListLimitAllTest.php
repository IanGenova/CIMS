<?php

namespace Tests\Datatest;

use Tests\Datatest\Concerns\W3Helpers;

/**
 * K65 — Choosing "All" rows (limit -1) on list screens returns a 500
 * (negative SQL LIMIT).
 */
class K65ListLimitAllTest extends DatatestCase
{
    use W3Helpers;

    private function assertListsAll($url)
    {
        $response = $this->actAs($this->owner)->getJson($url);

        $this->assertSame(200, $response->getStatusCode(),
            "per-page \"All\" (limit=-1) must list every row: $url (got HTTP {$response->getStatusCode()}: "
            . substr((string) $response->getContent(), 0, 200) . ')');
    }

    /**
     * K65 — GET /api/sales?limit=-1 returns a 500.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_sales_list_accepts_all_rows()
    {
        $this->assertListsAll('/api/sales?page=1&limit=-1&SortField=id&SortType=desc&search=');
    }

    /**
     * K65 — GET /api/clients?limit=-1 returns a 500.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_customer_list_accepts_all_rows()
    {
        $this->assertListsAll('/api/clients?page=1&limit=-1&SortField=id&SortType=desc&search=');
    }

    /**
     * K65 — GET /api/adjustments?limit=-1 returns a 500.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_adjustment_list_accepts_all_rows()
    {
        $this->assertListsAll('/api/adjustments?page=1&limit=-1&SortField=id&SortType=desc&search=');
    }

    /**
     * K65 — GET /api/transfers?limit=-1 returns a 500.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_the_transfer_list_accepts_all_rows()
    {
        $this->assertListsAll('/api/transfers?page=1&limit=-1&SortField=id&SortType=desc&search=');
    }
}
