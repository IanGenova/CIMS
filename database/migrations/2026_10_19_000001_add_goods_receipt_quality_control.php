<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddGoodsReceiptQualityControl extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_qc')->default(false)->after('has_serials');
        });

        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->string('qc_status', 24)->default('not_required')->index();
            $table->decimal('qc_passed_qte', 20, 6)->default(0);
            $table->decimal('qc_failed_qte', 20, 6)->default(0);
            $table->integer('qc_decided_by')->nullable();
            $table->timestamp('qc_decided_at')->nullable();
            $table->text('qc_notes')->nullable();
        });

        // Existing received goods were already made available under the old
        // workflow. Do not retroactively claim that they passed an inspection.
        DB::table('goods_receipt_details')->update([
            'qc_passed_qte' => DB::raw('received_qte'),
        ]);

        $permissionId = DB::table('permissions')->where('name', 'goods_receipt_qc_approve')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => 'goods_receipt_qc_approve', 'label' => 'Approve goods receipt QC',
            ]);
        $adminRoles = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('p.name', 'setting_system')->pluck('pr.role_id');
        foreach ($adminRoles as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId, 'role_id' => $roleId,
            ]);
        }
    }

    public function down()
    {
        $permissionId = DB::table('permissions')->where('name', 'goods_receipt_qc_approve')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::table('goods_receipt_details', function (Blueprint $table) {
            $table->dropIndex(['qc_status']);
            $table->dropColumn([
                'qc_status', 'qc_passed_qte', 'qc_failed_qte',
                'qc_decided_by', 'qc_decided_at', 'qc_notes',
            ]);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('requires_qc');
        });
    }
}
