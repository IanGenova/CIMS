<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who may change a price or give a discount (DATATEST K3; owner decision:
 * "Owner only; cashiers sell at the list price").
 *
 * The server now uses the product's price for everyone else. The permission is
 * granted to every role that can already change system settings -- the owner
 * and administrators -- and can be given to others on the Roles screen.
 */
class AddPriceOverridePermission extends Migration
{
    public function up()
    {
        $id = DB::table('permissions')->where('name', 'price_override')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'price_override', 'label' => 'price_override']);

        $roles = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('p.name', 'setting_system')
            ->pluck('pr.role_id');

        foreach ($roles as $roleId) {
            DB::table('permission_role')->updateOrInsert(['permission_id' => $id, 'role_id' => $roleId]);
        }
    }

    public function down()
    {
        $id = DB::table('permissions')->where('name', 'price_override')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
}
