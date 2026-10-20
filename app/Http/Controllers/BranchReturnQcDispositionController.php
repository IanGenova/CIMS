<?php

namespace App\Http\Controllers;

use App\utils\QcCustodyService;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manager decision and witnessed physical exit for failed branch pull-outs. */
class BranchReturnQcDispositionController extends BaseController
{
    private function permission(Request $request, $step)
    {
        abort_unless(DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $request->user('api')->role_id)
            ->where('p.name', 'branch_return_qc_disposition_' . $step)->exists(), 403);
    }

    private function source($itemId)
    {
        $source = DB::table('branch_shipment_items as i')
            ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
            ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
            ->join('branch_requests as r', 'r.id', '=', 'l.branch_request_id')
            ->where('i.id', $itemId)->where('r.kind', 'pullout')
            ->first(['i.id', 'i.qc_failed_qte', 'i.product_serial_id',
                'r.to_warehouse_id', 'r.id as request_id']);
        abort_unless($source, 404);
        WarehouseAccess::assertAllows($source->to_warehouse_id);
        return $source;
    }

    public function store(Request $request, $itemId)
    {
        $this->permission($request, 'request');
        $request->validate([
            'action' => 'required|in:write_off,supplier_return',
            'quantity' => 'required|numeric|gt:0',
            'reason' => 'required|string|min:3|max:5000',
        ]);
        $id = DB::transaction(function () use ($request, $itemId) {
            DB::table('branch_shipment_items')->where('id', $itemId)->lockForUpdate()->first();
            $source = $this->source($itemId);
            $claimed = (float) DB::table('branch_return_qc_dispositions')
                ->where('branch_shipment_item_id', $itemId)
                ->whereIn('status', ['pending', 'approved', 'executed'])->sum('quantity');
            if ($claimed + (float) $request->quantity > (float) $source->qc_failed_qte + 0.000001
                || ($source->product_serial_id && (float) $request->quantity !== 1.0)) {
                throw ValidationException::withMessages(['quantity' => ['Only failed, unclaimed units may be disposed.']]);
            }
            return DB::table('branch_return_qc_dispositions')->insertGetId([
                'branch_shipment_item_id' => $itemId,
                'action' => $request->action, 'quantity' => $request->quantity,
                'status' => 'pending', 'reason' => trim($request->reason),
                'requested_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function decide(Request $request, $id)
    {
        $this->permission($request, 'approve');
        $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:5000',
        ]);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('branch_return_qc_dispositions')->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->source($row->branch_shipment_item_id);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['qc' => ['This disposition has already been decided.']]);
            }
            if ((int) $row->requested_by === (int) $request->user('api')->id) {
                throw ValidationException::withMessages(['qc' => ['A different manager must decide this disposition.']]);
            }
            DB::table('branch_return_qc_dispositions')->where('id', $id)->update([
                'status' => $request->decision === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $request->user('api')->id,
                'decided_at' => now(), 'decision_reason' => $request->reason,
                'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function execute(Request $request, $id)
    {
        $this->permission($request, 'execute');
        $request->validate([
            'execution_reference' => 'required|string|min:3|max:120',
            'execution_note' => 'required|string|min:3|max:5000',
        ]);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('branch_return_qc_dispositions')->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row, 404);
            $source = $this->source($row->branch_shipment_item_id);
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['qc' => ['Only approved, unexecuted dispositions can be confirmed.']]);
            }
            if ((int) $row->decided_by === (int) $request->user('api')->id) {
                throw ValidationException::withMessages(['qc' => ['A different custodian must confirm physical handoff.']]);
            }
            $serialIds = $source->product_serial_id ? [(int) $source->product_serial_id] : [];
            if ($serialIds) {
                $status = DB::table('product_serials')->where('id', $serialIds[0])
                    ->lockForUpdate()->value('status');
                if ($status !== 'qc_rejected') {
                    throw ValidationException::withMessages(['qc' => ['The rejected serial changed state before handoff.']]);
                }
            }
            QcCustodyService::execute('branch_shipment_item', $source->id,
                (float) $row->quantity, $serialIds, $row->action, $id,
                $request->user('api')->id,
                $request->execution_reference . ' · ' . $request->execution_note);
            if ($serialIds) {
                $status = $row->action === 'supplier_return' ? 'returned_to_supplier' : 'written_off';
                DB::table('product_serials')->where('id', $serialIds[0])->update([
                    'status' => $status, 'warehouse_id' => null, 'updated_at' => now(),
                ]);
                DB::table('product_serial_events')->insert([
                    'product_serial_id' => $serialIds[0], 'event' => $status,
                    'warehouse_id' => $source->to_warehouse_id,
                    'reference_type' => 'BranchReturnQcDisposition', 'reference_id' => $id,
                    'user_id' => $request->user('api')->id, 'created_at' => now(),
                ]);
            }
            DB::table('branch_return_qc_dispositions')->where('id', $id)->update([
                'status' => 'executed', 'executed_by' => $request->user('api')->id,
                'executed_at' => now(), 'execution_reference' => trim($request->execution_reference),
                'execution_note' => trim($request->execution_note), 'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }
}
