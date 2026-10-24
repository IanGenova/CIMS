<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrackRejectedGoodsCustody extends Migration
{
    public function up()
    {
        Schema::create('qc_rejected_custody', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->integer('warehouse_id')->index();
            $table->integer('product_id')->index();
            $table->integer('product_variant_id')->nullable();
            $table->integer('product_batch_id')->nullable();
            $table->integer('product_serial_id')->nullable();
            $table->decimal('original_quantity', 20, 6);
            $table->decimal('remaining_quantity', 20, 6);
            $table->string('location', 120);
            $table->string('status', 20)->default('held');
            $table->integer('received_by')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
        Schema::create('qc_custody_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('custody_id')->index();
            $table->string('event', 32);
            $table->decimal('quantity', 20, 6);
            $table->string('from_location', 120)->nullable();
            $table->string('to_location', 120)->nullable();
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('created_at');
        });
        Schema::table('goods_receipt_qc_dispositions', function (Blueprint $table) {
            $table->integer('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('execution_reference', 120)->nullable();
            $table->text('execution_note')->nullable();
        });
        Schema::create('branch_return_qc_dispositions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_shipment_item_id')->index();
            $table->string('action', 20);
            $table->decimal('quantity', 20, 6);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->integer('requested_by');
            $table->integer('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->integer('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('execution_reference', 120)->nullable();
            $table->text('execution_note')->nullable();
            $table->timestamps();
        });
        $name = 'goods_receipt_qc_disposition_execute';
        $permissionId = DB::table('permissions')->where('name', $name)->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => $name, 'label' => 'Confirm physical disposition of failed goods',
            ]);
        $adminRoles = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('p.name', 'setting_system')->pluck('pr.role_id');
        foreach ($adminRoles as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId, 'role_id' => $roleId,
            ]);
        }
        foreach (['request', 'approve', 'execute'] as $step) {
            $name = 'branch_return_qc_disposition_' . $step;
            $id = DB::table('permissions')->where('name', $name)->value('id')
                ?: DB::table('permissions')->insertGetId([
                    'name' => $name, 'label' => ucfirst($step) . ' rejected branch return disposition',
                ]);
            $source = DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('p.name', 'goods_receipt_qc_disposition_' . $step)
                ->pluck('pr.role_id');
            foreach ($source as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $id, 'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down()
    {
        foreach (['request', 'approve', 'execute'] as $step) {
            $id = DB::table('permissions')->where('name', 'branch_return_qc_disposition_' . $step)->value('id');
            if ($id) {
                DB::table('permission_role')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        $id = DB::table('permissions')->where('name', 'goods_receipt_qc_disposition_execute')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::table('goods_receipt_qc_dispositions', function (Blueprint $table) {
            $table->dropColumn(['executed_by', 'executed_at', 'execution_reference', 'execution_note']);
        });
        Schema::dropIfExists('qc_custody_events');
        Schema::dropIfExists('qc_rejected_custody');
        Schema::dropIfExists('branch_return_qc_dispositions');
    }
}
