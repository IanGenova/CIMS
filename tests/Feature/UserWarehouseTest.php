<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\GrantsPermissions;
use Tests\TestCase;

/**
 * Giving a staff member their warehouses, on the Users screen
 * (POST /api/users, PUT /api/users/{id}). See WarehouseAccessTest for what
 * the assignment then does.
 */
class UserWarehouseTest extends TestCase
{
    use RefreshDatabase;
    use GrantsPermissions;

    private $main;
    private $branch;
    private $roleId;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->actAsUserWith(['users_view', 'users_add', 'users_edit', 'record_view']);
        $this->roleId = $admin->role_id;
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
    }

    private function create(array $extra)
    {
        return $this->postJson('/api/users', array_merge([
            'firstname' => 'Juan', 'lastname' => 'Cruz', 'username' => 'juan', 'email' => 'juan@example.invalid',
            'password' => 'Str0ng!Passw0rd', 'phone' => '0917', 'role' => $this->roleId,
        ], $extra));
    }

    /** @test */
    public function a_new_user_is_on_all_warehouses_unless_told_otherwise()
    {
        $this->create([])->assertSuccessful();

        $user = DB::table('users')->where('email', 'juan@example.invalid')->first();
        $this->assertSame(1, (int) $user->all_warehouses);
    }

    /** @test */
    public function a_new_user_can_be_given_one_branch()
    {
        $this->create(['all_warehouses' => 0, 'warehouse_ids' => [$this->main]])->assertSuccessful();

        $user = DB::table('users')->where('email', 'juan@example.invalid')->first();
        $this->assertSame(0, (int) $user->all_warehouses);
        $this->assertEquals([$this->main], DB::table('user_warehouse')->where('user_id', $user->id)->pluck('warehouse_id')->all());
    }

    /** @test */
    public function a_branch_user_needs_at_least_one_warehouse()
    {
        $this->create(['all_warehouses' => 0, 'warehouse_ids' => []])->assertStatus(422);
    }

    /** @test */
    public function an_existing_user_can_be_moved_to_another_branch()
    {
        $this->create(['all_warehouses' => 0, 'warehouse_ids' => [$this->main]])->assertSuccessful();
        $id = DB::table('users')->where('email', 'juan@example.invalid')->value('id');

        $this->putJson("/api/users/{$id}", [
            'firstname' => 'Juan', 'lastname' => 'Cruz', 'username' => 'juan', 'email' => 'juan@example.invalid',
            'phone' => '0917', 'role' => $this->roleId, 'statut' => 1,
            // As the Users screen sends them: no new password, the avatar unchanged.
            'NewPassword' => 'null', 'avatar' => 'no_avatar.png',
            'all_warehouses' => 0, 'warehouse_ids' => [$this->branch],
        ])->assertSuccessful();

        $this->assertEquals([$this->branch], DB::table('user_warehouse')->where('user_id', $id)->pluck('warehouse_id')->all());
    }

    /** @test */
    public function the_users_list_says_who_is_on_which_warehouses()
    {
        $this->create(['all_warehouses' => 0, 'warehouse_ids' => [$this->branch]])->assertSuccessful();

        $response = $this->getJson('/api/users?page=1&limit=10&SortField=id&SortType=desc&search=')->assertOk();
        $juan = collect($response->json('users'))->firstWhere('email', 'juan@example.invalid');

        $this->assertFalse((bool) $juan['all_warehouses']);
        $this->assertEquals([$this->branch], $juan['warehouse_ids']);
        $this->assertSame(['Main Warehouse', 'Branch Warehouse'], collect($response->json('warehouses'))->pluck('name')->all());
    }
}
