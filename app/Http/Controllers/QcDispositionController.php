<?php

namespace App\Http\Controllers;

use App\utils\SerialService;
use App\utils\QcCustodyService;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Failed receipt goods are physically held until a different manager decides their fate. */
class QcDispositionController extends BaseController
{
    private function requirePermission(Request $request, $name)
    {
        abort_unless(DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $request->user('api')->role_id)
            ->where('p.name', $name)->exists(), 403);
    }

    public function index(Request $request)
    {
        $this->requirePermission($request, 'goods_receipt_view');
        $rows = DB::table('goods_receipt_qc_dispositions as q')
            ->join('goods_receipt_details as d', 'd.id', '=', 'q.goods_receipt_detail_id')
            ->join('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->whereNull('gr.deleted_at')
            ->when(WarehouseAccess::allowedIds() !== null,
                fn ($query) => $query->whereIn('gr.warehouse_id', WarehouseAccess::allowedIds()))
            ->orderByDesc('q.id')->limit(100)
            ->get(['q.*', 'gr.Ref as receipt_ref', 'gr.warehouse_id',
                'p.code as product_code', 'p.name as product']);
        return response()->json(['dispositions' => $rows]);
    }

    public function store(Request $request, $receiptId)
    {
        $this->requirePermission($request, 'goods_receipt_qc_disposition_request');
        $request->validate([
            'receipt_detail_id' => 'required|integer',
            'action' => 'required|in:supplier_return,write_off',
            'quantity' => 'required|numeric|gt:0',
            'reason' => 'required|string|max:5000',
            'serial_numbers' => 'nullable|array',
            'serial_numbers.*' => 'string|max:100',
        ]);

        $id = DB::transaction(function () use ($request, $receiptId) {
            $receipt = DB::table('goods_receipts')->where('id', $receiptId)
                ->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($receipt, 404);
            WarehouseAccess::assertAllows($receipt->warehouse_id);
            $line = DB::table('goods_receipt_details')
                ->where('id', $request->receipt_detail_id)
                ->where('goods_receipt_id', $receiptId)->lockForUpdate()->first();
            abort_unless($line, 404);
            if (! in_array($line->qc_status, ['failed', 'partial'], true)) {
                throw ValidationException::withMessages(['qc' => ['This receipt line has no failed QC quantity.']]);
            }
            $claimed = (float) DB::table('goods_receipt_qc_dispositions')
                ->where('goods_receipt_detail_id', $line->id)
                ->whereIn('status', ['pending', 'approved', 'executed'])->sum('quantity');
            if ($claimed + (float) $request->quantity > (float) $line->qc_failed_qte + 0.000001) {
                throw ValidationException::withMessages(['quantity' => ['Only the remaining failed quantity may be disposed.']]);
            }
            $serialNumbers = [];
            if (SerialService::isTracked($line->product_id)) {
                $purchaseLine = DB::table('purchase_details')
                    ->where('id', $line->purchase_detail_id)->first();
                $unit = $purchaseLine ? DB::table('units')
                    ->where('id', $purchaseLine->purchase_unit_id)->first() : null;
                if (! $unit || (float) $unit->operator_value <= 0) {
                    throw ValidationException::withMessages(['serial_numbers' => ['The purchase unit is missing.']]);
                }
                $baseQty = $unit->operator === '/'
                    ? (float) $request->quantity / (float) $unit->operator_value
                    : (float) $request->quantity * (float) $unit->operator_value;
                $serialNumbers = collect($request->input('serial_numbers', []))
                    ->map(fn ($number) => strtoupper(trim($number)))->all();
                if (abs($baseQty - count($serialNumbers)) > 0.000001
                    || count($serialNumbers) !== count(array_unique($serialNumbers))) {
                    throw ValidationException::withMessages(['serial_numbers' => ['Name every failed serial in this disposition exactly once.']]);
                }
                $valid = DB::table('product_serials')->where('source_receipt_detail_id', $line->id)
                    ->where('status', 'qc_rejected')
                    ->whereIn('serial_number', $serialNumbers)->count();
                if ($valid !== count($serialNumbers)) {
                    throw ValidationException::withMessages(['serial_numbers' => ['One or more named units did not fail QC on this receipt.']]);
                }
                $claimedSerials = DB::table('goods_receipt_qc_dispositions')
                    ->where('goods_receipt_detail_id', $line->id)
                    ->whereIn('status', ['pending', 'approved', 'executed'])->pluck('serial_numbers')
                    ->flatMap(fn ($json) => json_decode($json ?: '[]', true) ?: [])->all();
                if (array_intersect($serialNumbers, $claimedSerials)) {
                    throw ValidationException::withMessages(['serial_numbers' => ['A serial is already in another disposition.']]);
                }
            } elseif ($request->filled('serial_numbers')) {
                throw ValidationException::withMessages(['serial_numbers' => ['This product does not use serial numbers.']]);
            }
            return DB::table('goods_receipt_qc_dispositions')->insertGetId([
                'goods_receipt_detail_id' => $line->id,
                'action' => $request->action,
                'quantity' => $request->quantity,
                'status' => 'pending',
                'reason' => $request->reason,
                'serial_numbers' => $serialNumbers ? json_encode($serialNumbers) : null,
                'requested_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function decide(Request $request, $id)
    {
        $this->requirePermission($request, 'goods_receipt_qc_disposition_approve');
        $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'required_if:decision,reject|nullable|string|max:5000',
        ]);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('goods_receipt_qc_dispositions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $receipt = DB::table('goods_receipt_details as d')
                ->join('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
                ->where('d.id', $row->goods_receipt_detail_id)->whereNull('gr.deleted_at')
                ->first(['gr.warehouse_id']);
            abort_unless($receipt, 404);
            WarehouseAccess::assertAllows($receipt->warehouse_id);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['qc' => ['This disposition has already been decided.']]);
            }
            if ((int) $row->requested_by === (int) $request->user('api')->id) {
                throw ValidationException::withMessages(['qc' => ['A different manager must decide this disposition.']]);
            }
            DB::table('goods_receipt_qc_dispositions')->where('id', $id)->update([
                'status' => $request->decision === 'approve' ? 'approved' : 'rejected',
                'decided_by' => $request->user('api')->id,
                'decided_at' => now(),
                'decision_reason' => $request->reason,
                'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function execute(Request $request, $id)
    {
        $this->requirePermission($request, 'goods_receipt_qc_disposition_execute');
        $request->validate([
            'execution_reference' => 'required|string|min:3|max:120',
            'execution_note' => 'required|string|min:3|max:5000',
        ]);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('goods_receipt_qc_dispositions')->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row, 404);
            $source = DB::table('goods_receipt_details as d')
                ->join('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
                ->join('purchase_details as pd', 'pd.id', '=', 'd.purchase_detail_id')
                ->join('units as u', 'u.id', '=', 'pd.purchase_unit_id')
                ->where('d.id', $row->goods_receipt_detail_id)->whereNull('gr.deleted_at')
                ->first(['d.id', 'gr.warehouse_id', 'u.operator', 'u.operator_value']);
            abort_unless($source, 404);
            WarehouseAccess::assertAllows($source->warehouse_id);
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['qc' => ['Only an approved, unexecuted disposition can be confirmed.']]);
            }
            if ((int) $row->decided_by === (int) $request->user('api')->id) {
                throw ValidationException::withMessages(['qc' => ['A different custodian must confirm physical handoff.']]);
            }
            $baseQuantity = $source->operator === '/'
                ? (float) $row->quantity / (float) $source->operator_value
                : (float) $row->quantity * (float) $source->operator_value;
            $numbers = json_decode($row->serial_numbers ?: '[]', true) ?: [];
            $serials = collect();
            if ($numbers) {
                $serials = DB::table('product_serials')
                    ->where('source_receipt_detail_id', $source->id)
                    ->whereIn('serial_number', $numbers)->lockForUpdate()->get();
                if ($serials->count() !== count($numbers)
                    || $serials->contains(fn ($serial) => $serial->status !== 'qc_rejected')) {
                    throw ValidationException::withMessages(['serial_numbers' => ['A rejected serial changed state before handoff.']]);
                }
            }
            QcCustodyService::execute('goods_receipt_detail', $source->id,
                $baseQuantity, $serials->pluck('id')->all(), $row->action,
                $id, $request->user('api')->id,
                $request->execution_reference . ' · ' . $request->execution_note);
            $status = $row->action === 'supplier_return' ? 'returned_to_supplier' : 'written_off';
            foreach ($serials as $serial) {
                DB::table('product_serials')->where('id', $serial->id)->update([
                    'status' => $status, 'warehouse_id' => null, 'updated_at' => now(),
                ]);
                DB::table('product_serial_events')->insert([
                    'product_serial_id' => $serial->id, 'event' => $status,
                    'warehouse_id' => $source->warehouse_id,
                    'reference_type' => 'QcDisposition', 'reference_id' => $id,
                    'user_id' => $request->user('api')->id, 'created_at' => now(),
                ]);
            }
            DB::table('goods_receipt_qc_dispositions')->where('id', $id)->update([
                'status' => 'executed', 'executed_by' => $request->user('api')->id,
                'executed_at' => now(), 'execution_reference' => $request->execution_reference,
                'execution_note' => $request->execution_note, 'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function moveCustody(Request $request, $id)
    {
        $this->requirePermission($request, 'goods_receipt_qc_disposition_execute');
        $request->validate([
            'location' => 'required|string|min:2|max:120',
            'note' => 'required|string|min:3|max:1000',
        ]);
        DB::transaction(function () use ($request, $id) {
            $row = DB::table('qc_rejected_custody')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            WarehouseAccess::assertAllows($row->warehouse_id);
            if ($row->status !== 'held' || $row->location === trim($request->location)) {
                throw ValidationException::withMessages(['custody' => ['Choose a different location for held goods.']]);
            }
            $location = trim($request->location);
            DB::table('qc_rejected_custody')->where('id', $id)->update([
                'location' => $location, 'updated_at' => now(),
            ]);
            QcCustodyService::event($id, 'moved', $row->remaining_quantity,
                $row->location, $location, $row->source_type, $row->source_id,
                $request->user('api')->id, $request->note);
        }, 5);
        return response()->json(['success' => true]);
    }
}
