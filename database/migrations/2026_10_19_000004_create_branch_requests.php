<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateBranchRequests extends Migration
{
    public function up()
    {
        Schema::create('branch_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Ref', 40)->unique();
            $table->integer('from_warehouse_id')->index();
            $table->integer('to_warehouse_id')->index();
            $table->string('status', 24)->default('submitted');
            $table->string('priority', 12)->default('normal');
            $table->text('notes')->nullable();
            $table->integer('requested_by');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_request_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_request_id')->index();
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('requested_qte', 20, 6);
            $table->timestamps();
        });
        Schema::create('branch_shipments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Ref', 40)->unique();
            $table->unsignedBigInteger('branch_request_line_id')->index();
            $table->decimal('quantity', 20, 6);
            $table->string('status', 24)->default('allocated');
            $table->integer('approved_by');
            $table->integer('dispatched_by')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->integer('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('discrepancy_notes')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_shipment_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_shipment_id')->index();
            $table->unsignedInteger('product_batch_id')->nullable()->index();
            $table->unsignedInteger('product_serial_id')->nullable()->index();
            $table->decimal('quantity', 20, 6);
            $table->decimal('received_qte', 20, 6)->default(0);
            $table->timestamps();
        });
        $name = 'branch_request_approve';
        $permissionId = DB::table('permissions')->where('name', $name)->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => $name, 'label' => 'Approve branch stock requests',
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
        $id = DB::table('permissions')->where('name', 'branch_request_approve')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::dropIfExists('branch_shipment_items');
        Schema::dropIfExists('branch_shipments');
        Schema::dropIfExists('branch_request_lines');
        Schema::dropIfExists('branch_requests');
    }
}
