<?php

namespace Tests\Datatest;

use Illuminate\Support\Facades\DB;
use Tests\Datatest\Concerns\W3Helpers;

/**
 * K70 — Deleting a nonexistent customer, supplier, currency, unit or warehouse
 * returns 200 "success", and re-deleting a deleted record overwrites deleted_at.
 */
class K70DeleteMissingRecordTest extends DatatestCase
{
    use W3Helpers;

    /**
     * K70 — DELETE of id 999999 answers {"success":true} for customers, suppliers, currencies, units and warehouses.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_deleting_a_record_that_does_not_exist_is_a_404()
    {
        $this->actAs($this->owner);
        $wrong = [];
        foreach (['clients', 'providers', 'currencies', 'units', 'warehouses'] as $resource) {
            $status = $this->deleteJson("/api/{$resource}/999999")->getStatusCode();
            if ($status !== 404) {
                $wrong[] = "$resource ($status)";
            }
        }

        $this->assertSame([], $wrong, 'nothing was deleted, so the answer is 404, not success');
    }

    /**
     * K70 — Deleting an already-deleted customer answers 200 and overwrites the original deleted_at.
     * Red until repaired (.flow/DATATEST-2026-10-06.md).
     */
    public function test_re_deleting_a_customer_keeps_the_original_delete_time()
    {
        $when = '2026-10-01 09:00:00';
        $gone = DB::table('clients')->insertGetId(['name' => 'Old customer', 'code' => 2, 'deleted_at' => $when]);

        $response = $this->actAs($this->owner)->deleteJson("/api/clients/{$gone}");

        $this->assertSame(404, $response->getStatusCode(), 'the customer is already deleted');
        $this->assertSame($when, (string) DB::table('clients')->where('id', $gone)->value('deleted_at'),
            'the original delete time must be kept');
    }
}
