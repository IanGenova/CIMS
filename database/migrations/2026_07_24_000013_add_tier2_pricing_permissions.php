<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions for the Tier 2 pricing modules.
 *
 * Granted to every role that already holds "currency", on the grounds that
 * whoever is trusted to define the money units is the same person trusted to
 * define tax and price rules. No other role gains anything.
 */
class AddTier2PricingPermissions extends Migration
{
    private $permissions = [
        'tax_group' => 'Tax Groups',
        'customer_group' => 'Customer Groups and Price Lists',
    ];

    public function up()
    {
        $reference = DB::table('permissions')->where('name', 'currency')->first();
        $roleIds = $reference
            ? DB::table('permission_role')->where('permission_id', $reference->id)->pluck('role_id')->all()
            : [];

        foreach ($this->permissions as $name => $label) {
            $existing = DB::table('permissions')->where('name', $name)->first();

            if ($existing) {
                $permissionId = $existing->id;
            } else {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'label' => $label,
                    'description' => $label,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($roleIds as $roleId) {
                $already = DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->where('role_id', $roleId)->exists();

                if (! $already) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }
    }

    public function down()
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
}
