<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BranchStaff;
use Tests\TestCase;

/** The quotations list (GET /api/quotations) shows a branch only its own. */
class QuotationWarehouseTest extends TestCase
{
    use RefreshDatabase;
    use BranchStaff;

    /** @test */
    public function a_branch_sees_only_its_own_quotations()
    {
        $this->setUpBranches();
        foreach ([[$this->main, 'QT_MAIN'], [$this->branch, 'QT_BRANCH']] as [$w, $ref]) {
            DB::table('quotations')->insert(['user_id' => $this->author, 'date' => now()->toDateString(), 'Ref' => $ref,
                'client_id' => $this->client, 'warehouse_id' => $w, 'GrandTotal' => 1000, 'statut' => 'pending']);
        }
        $url = '/api/quotations?page=1&limit=10&SortField=id&SortType=desc&search=';

        $this->assertSame(['QT_MAIN'], $this->refsListed($this->staffOn(['Quotations_view', 'record_view'], [$this->main]), $url, 'quotations'));
        $this->assertSame(['QT_BRANCH', 'QT_MAIN'], $this->refsListed($this->staffOn(['Quotations_view', 'record_view']), $url, 'quotations'));
    }
}
