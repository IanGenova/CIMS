<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateQcDispositions extends Migration
{
    public function up()
    {
        Schema::create('goods_receipt_qc_dispositions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('goods_receipt_detail_id')->index();
            $table->string('action', 24); // supplier_return or write_off
            $table->decimal('quantity', 20, 6);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->integer('requested_by');
            $table->integer('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });

        foreach ([
            'goods_receipt_qc_disposition_request' => 'Request disposition of failed goods',
            'goods_receipt_qc_disposition_approve' => 'Approve disposition of failed goods',
        ] as $name => $label) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id')
                ?: DB::table('permissions')->insertGetId(compact('name', 'label'));
            $adminRoles = DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('p.name', 'setting_system')->pluck('pr.role_id');
            foreach ($adminRoles as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId, 'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down()
    {
        foreach (['goods_receipt_qc_disposition_request', 'goods_receipt_qc_disposition_approve'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id) {
                DB::table('permission_role')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        Schema::dropIfExists('goods_receipt_qc_dispositions');
    }
}
