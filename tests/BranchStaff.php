<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Two branches and staff tied to them, for the per-branch list tests
 * (see Feature\WarehouseAccessTest for the full rules).
 */
trait BranchStaff
{
    protected $main;
    protected $branch;
    protected $client;
    protected $author;
    private static $branchSeq = 0;

    protected function setUpBranches()
    {
        $this->main = DB::table('warehouses')->insertGetId(['name' => 'Main Warehouse']);
        $this->branch = DB::table('warehouses')->insertGetId(['name' => 'Branch Warehouse']);
        $this->client = DB::table('clients')->insertGetId(['name' => 'walk-in-customer', 'code' => 1]);
        $this->author = $this->staffOn(['record_view'])->id;
    }

    /** A user with these permissions, on all warehouses or only those given. */
    protected function staffOn(array $permissions, array $warehouses = null)
    {
        $i = ++self::$branchSeq;
        $role = DB::table('roles')->insertGetId(['name' => "b$i", 'label' => "B$i"]);
        foreach ($permissions as $p) {
            $pid = DB::table('permissions')->where('name', $p)->value('id') ?? DB::table('permissions')->insertGetId(['name' => $p, 'label' => $p]);
            DB::table('permission_role')->insert(['permission_id' => $pid, 'role_id' => $role]);
        }
        $user = User::create(['firstname' => 'B', 'lastname' => (string) $i, 'username' => "b$i", 'email' => "b$i@example.invalid",
            'password' => bcrypt('Str0ngEnough'), 'statut' => 1, 'role_id' => $role, 'phone' => '0917']);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $user->id]);
        if ($warehouses !== null) {
            DB::table('users')->where('id', $user->id)->update(['all_warehouses' => 0]);
            foreach ($warehouses as $w) {
                DB::table('user_warehouse')->insert(['user_id' => $user->id, 'warehouse_id' => $w]);
            }
        }

        return $user->fresh();
    }

    protected function refsListed($user, $url, $key)
    {
        return collect($this->actingAs($user, 'api')->getJson($url)->assertOk()->json($key))->pluck('Ref')->sort()->values()->all();
    }
}
