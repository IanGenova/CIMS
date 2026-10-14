<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permissions for the remaining Tier 2 modules.
 *
 * Accounting and manufacturing are new responsibilities, so they get their own
 * permissions rather than riding on an existing one. Both are granted to the
 * roles that already hold "currency" -- the same reasoning as the pricing
 * modules: whoever configures the money rules gets these too, and nobody else
 * gains anything until an administrator says so.
 *
 * Bin locations and deliveries deliberately have no new permission: they are
 * governed by the warehouse and sale permissions that already exist, because
 * they are the same job as managing a warehouse and shipping an order.
 */
class AddTier2OperationsPermissions extends Migration
{
    private $permissions = [
        'accounting' => 'Accounting and Journal',
        'manufacturing' => 'Bills of Material and Production',
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
