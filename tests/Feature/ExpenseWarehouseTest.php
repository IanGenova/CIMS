<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BranchStaff;
use Tests\TestCase;

/** The expenses list (GET /api/expenses) shows a branch only its own. */
class ExpenseWarehouseTest extends TestCase
{
    use RefreshDatabase;
    use BranchStaff;

    /** @test */
    public function a_branch_sees_only_its_own_expenses()
    {
        $this->setUpBranches();
        $category = DB::table('expense_categories')->insertGetId(['name' => 'Utilities', 'user_id' => $this->author]);
        foreach ([[$this->main, 'EX_MAIN'], [$this->branch, 'EX_BRANCH']] as [$w, $ref]) {
            DB::table('expenses')->insert(['date' => now()->toDateString(), 'Ref' => $ref, 'user_id' => $this->author,
                'expense_category_id' => $category, 'warehouse_id' => $w, 'details' => 'Power', 'amount' => 450]);
        }
        $url = '/api/expenses?page=1&limit=10&SortField=id&SortType=desc&search=';

        $this->assertSame(['EX_MAIN'], $this->refsListed($this->staffOn(['expense_view', 'record_view'], [$this->main]), $url, 'expenses'));
        $this->assertSame(['EX_BRANCH', 'EX_MAIN'], $this->refsListed($this->staffOn(['expense_view', 'record_view']), $url, 'expenses'));
    }
}
