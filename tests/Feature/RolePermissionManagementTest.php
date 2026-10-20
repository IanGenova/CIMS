<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

class RolePermissionManagementTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    /** @test */
    public function group_editor_lists_new_workflow_permissions_and_saves_exact_selections()
    {
        $this->actAsUserWith(['permissions_view', 'permissions_add', 'permissions_edit']);

        $catalog = collect($this->getJson('/api/roles/permission-catalog')->assertOk()
            ->json('permissions'))->pluck('name');
        $this->assertContains('goods_receipt_qc_approve', $catalog);
        $this->assertContains('branch_return_qc_disposition_execute', $catalog);
        $this->assertContains('branch_request_approve', $catalog);

        $this->postJson('/api/roles', [
            'role' => ['name' => 'Branch QC Clerk', 'description' => 'Inspects branch returns'],
            'permissions' => ['goods_receipt_qc_approve', 'branch_return_qc_disposition_request'],
        ])->assertOk();
        $roleId = DB::table('roles')->where('name', 'Branch QC Clerk')->value('id');
        $this->getJson("/api/roles/{$roleId}/edit")->assertOk()
            ->assertJsonPath('role.name', 'Branch QC Clerk')
            ->assertJsonCount(2, 'permissions');

        $this->putJson("/api/roles/{$roleId}", [
            'role' => ['name' => 'Branch QC Lead', 'description' => 'Approves return QC'],
            'permissions' => ['branch_return_qc_disposition_approve'],
        ])->assertOk();
        $this->assertEquals(['branch_return_qc_disposition_approve'],
            DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('pr.role_id', $roleId)->pluck('p.name')->all());

        $this->putJson("/api/roles/{$roleId}", [
            'role' => ['name' => 'Branch QC Lead', 'description' => 'Awaiting assignment'],
            'permissions' => [],
        ])->assertOk();
        $this->assertEquals(0, DB::table('permission_role')->where('role_id', $roleId)->count());
    }

    /** @test */
    public function group_editor_rejects_unknown_permissions_without_changing_a_role()
    {
        $this->actAsUserWith(['permissions_edit']);
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Warehouse Clerk', 'label' => 'Warehouse Clerk', 'description' => 'Original',
        ]);
        $this->putJson("/api/roles/{$roleId}", [
            'role' => ['name' => 'Changed', 'description' => 'Changed'],
            'permissions' => ['nonexistent_power'],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions.0');
        $this->assertSame('Warehouse Clerk', DB::table('roles')->where('id', $roleId)->value('name'));
    }
}
