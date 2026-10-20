<?php

namespace App\Http\Controllers;

use App\Models\product_warehouse;
use App\utils\BatchService;
use App\utils\DocumentSequence;
use App\utils\ReservationService;
use App\utils\SerialService;
use App\utils\StockLedger;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Branch replenishment: a source promise, physical dispatch, then branch acceptance. */
class BranchRequestController extends BaseController
{
    private function permission(Request $request, $name)
    {
        abort_unless(DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $request->user('api')->role_id)
            ->where('p.name', $name)->exists(), 403);
    }

    private function fail($message)
    {
        throw ValidationException::withMessages(['branch_request' => [$message]]);
    }

    private function visible($request)
    {
        if (WarehouseAccess::allowedIds() !== null) {
            abort_unless(WarehouseAccess::allows($request->from_warehouse_id)
                || WarehouseAccess::allows($request->to_warehouse_id), 403);
        }
    }

    private function detail($id)
    {
        $request = DB::table('branch_requests')->where('id', $id)->first();
        abort_unless($request, 404);
        $this->visible($request);
        $request->lines = DB::table('branch_request_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.branch_request_id', $id)->orderBy('l.id')
            ->get(['l.*', 'p.code', 'p.name', 'p.requires_qc']);
        foreach ($request->lines as $line) {
            $line->shipments = DB::table('branch_shipments')
                ->where('branch_request_line_id', $line->id)->orderBy('id')->get();
            foreach ($line->shipments as $shipment) {
                $shipment->items = DB::table('branch_shipment_items as i')
                    ->leftJoin('product_batches as b', 'b.id', '=', 'i.product_batch_id')
                    ->leftJoin('product_serials as s', 's.id', '=', 'i.product_serial_id')
                    ->where('i.branch_shipment_id', $shipment->id)
                    ->get(['i.*', 'b.batch_number', 's.serial_number']);
                foreach ($shipment->items as $item) {
                    $item->qc_decisions = DB::table('branch_shipment_qc_decisions as q')
                        ->leftJoin('users as u', 'u.id', '=', 'q.decided_by')
                        ->where('q.branch_shipment_item_id', $item->id)
                        ->orderBy('q.id')->get(['q.*', 'u.username as decider']);
                }
            }
        }
        return $request;
    }

    public function index(Request $request)
    {
        $this->permission($request, 'transfer_view');
        $rows = DB::table('branch_requests as r')
            ->join('warehouses as src', 'src.id', '=', 'r.from_warehouse_id')
            ->join('warehouses as dst', 'dst.id', '=', 'r.to_warehouse_id')
            ->when(WarehouseAccess::allowedIds() !== null, function ($q) {
                $ids = WarehouseAccess::allowedIds();
                $q->where(fn ($query) => $query->whereIn('r.from_warehouse_id', $ids)
                    ->orWhereIn('r.to_warehouse_id', $ids));
            })
            ->orderByDesc('r.id')->limit(100)
            ->get(['r.*', 'src.name as source', 'dst.name as destination']);
        return response()->json(['requests' => $rows]);
    }

    public function options(Request $request)
    {
        $this->permission($request, 'transfer_view');
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')
            ->whereIn('site_type', ['central_warehouse', 'main_store', 'branch'])
            ->orderBy('name')->get(['id', 'name', 'site_type']);
        $products = DB::table('products')->whereNull('deleted_at')
            ->where('is_active', 1)->where('is_service', 0)
            ->orderBy('name')->limit(500)->get(['id', 'code', 'name']);
        $variants = DB::table('product_variants')->whereIn('product_id', $products->pluck('id'))
            ->orderBy('name')->get(['id', 'product_id', 'name']);
        return response()->json(['warehouses' => $warehouses, 'products' => $products,
            'variants' => $variants]);
    }

    public function show(Request $request, $id)
    {
        $this->permission($request, 'transfer_view');
        return response()->json(['request' => $this->detail($id)]);
    }

    public function store(Request $request)
    {
        $this->permission($request, 'transfer_add');
        $request->validate([
            'from_warehouse_id' => 'required|integer|different:to_warehouse_id|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id',
            'priority' => 'nullable|in:normal,urgent',
            'kind' => 'nullable|in:replenishment,pullout',
            'notes' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);
        $kind = $request->kind ?: 'replenishment';
        WarehouseAccess::assertAllows($kind === 'pullout'
            ? $request->from_warehouse_id : $request->to_warehouse_id);
        $source = DB::table('warehouses')->where('id', $request->from_warehouse_id)->whereNull('deleted_at')->first();
        $destination = DB::table('warehouses')->where('id', $request->to_warehouse_id)->whereNull('deleted_at')->first();
        if (! $source || ! $destination || ($kind === 'replenishment'
            ? ($source->site_type !== 'central_warehouse'
                || ! in_array($destination->site_type, ['main_store', 'branch'], true))
            : (! in_array($source->site_type, ['main_store', 'branch'], true)
                || $destination->site_type !== 'central_warehouse'))) {
            $this->fail('Choose a central warehouse and a main store or branch in the correct direction.');
        }
        if ($kind === 'pullout' && ! $request->filled('notes')) {
            $this->fail('Record why the branch is pulling this stock out.');
        }
        $id = DB::transaction(function () use ($request, $kind) {
            $id = DB::table('branch_requests')->insertGetId([
                'Ref' => DocumentSequence::next('branch_requests', 'BR-', 6),
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
                'kind' => $kind,
                'priority' => $request->priority ?: 'normal',
                'status' => 'submitted', 'notes' => $request->notes,
                'requested_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($request->lines as $line) {
                if (DB::table('products')->where('id', $line['product_id'])->value('is_service')) {
                    $this->fail('A service cannot be requested as branch stock.');
                }
                if (isset($line['product_variant_id']) && ! DB::table('product_variants')
                    ->where('id', $line['product_variant_id'])
                    ->where('product_id', $line['product_id'])->exists()) {
                    $this->fail('The selected variant does not belong to the product.');
                }
                DB::table('branch_request_lines')->insert([
                    'branch_request_id' => $id, 'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'requested_qte' => $line['quantity'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $id;
        }, 5);
        return response()->json(['id' => $id], 201);
    }

    /** Approve one full or partial fulfillment wave and reserve its exact stock. */
    public function allocate(Request $request, $id)
    {
        $this->permission($request, 'branch_request_approve');
        $request->validate(['line_id' => 'required|integer', 'quantity' => 'required|numeric|gt:0']);
        $shipmentId = DB::transaction(function () use ($request, $id) {
            $order = DB::table('branch_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            WarehouseAccess::assertAllows($order->from_warehouse_id);
            if ($order->status === 'cancelled') $this->fail('This request was cancelled.');
            if ((int) $order->requested_by === (int) $request->user('api')->id) {
                $this->fail('A different manager must approve this branch request.');
            }
            $line = DB::table('branch_request_lines')->where('id', $request->line_id)
                ->where('branch_request_id', $id)->lockForUpdate()->first();
            abort_unless($line, 404);
            $allocated = (float) DB::table('branch_shipments')
                ->where('branch_request_line_id', $line->id)
                ->whereNotIn('status', ['cancelled'])->sum('quantity');
            if ($allocated + (float) $request->quantity > (float) $line->requested_qte + 0.000001) {
                $this->fail('Allocation exceeds the remaining request quantity.');
            }
            $shipmentId = DB::table('branch_shipments')->insertGetId([
                'Ref' => DocumentSequence::next('branch_shipments', 'BS-', 6),
                'branch_request_line_id' => $line->id,
                'quantity' => $request->quantity, 'status' => 'allocated',
                'approved_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ReservationService::reserve('branch_shipment', $shipmentId,
                $order->from_warehouse_id, $line->product_id, $line->product_variant_id,
                $request->quantity, $request->user('api')->id);
            DB::table('branch_requests')->where('id', $id)->update([
                'status' => 'allocated', 'updated_at' => now(),
            ]);
            return $shipmentId;
        }, 5);
        return response()->json(['shipment_id' => $shipmentId], 201);
    }

    /** Release every undelivered allocation when a request is cancelled. */
    public function cancel(Request $request, $id)
    {
        $this->permission($request, 'transfer_edit');
        $request->validate(['reason' => 'required|string|min:3|max:5000']);
        DB::transaction(function () use ($request, $id) {
            $order = DB::table('branch_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($order, 404);
            WarehouseAccess::assertAllows($order->kind === 'pullout'
                ? $order->from_warehouse_id : $order->to_warehouse_id);
            if ($order->status === 'cancelled') return;
            $shipments = DB::table('branch_shipments as s')
                ->join('branch_request_lines as l', 'l.id', '=', 's.branch_request_line_id')
                ->where('l.branch_request_id', $id)->lockForUpdate()->get(['s.*']);
            if ($shipments->contains(fn ($shipment) => ! in_array($shipment->status, ['allocated', 'cancelled'], true))) {
                $this->fail('A dispatched request cannot be cancelled. Receive it and use the return workflow.');
            }
            foreach ($shipments->where('status', 'allocated') as $shipment) {
                ReservationService::close('branch_shipment', $shipment->id, 'released',
                    $request->user('api')->id, 'Branch request cancelled: ' . $request->reason);
                DB::table('branch_shipments')->where('id', $shipment->id)->update([
                    'status' => 'cancelled', 'cancelled_by' => $request->user('api')->id,
                    'cancelled_at' => now(), 'cancel_reason' => $request->reason,
                    'updated_at' => now(),
                ]);
            }
            DB::table('branch_requests')->where('id', $id)->update([
                'status' => 'cancelled', 'cancelled_by' => $request->user('api')->id,
                'cancelled_at' => now(), 'cancel_reason' => $request->reason,
                'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function dispatchShipment(Request $request, $shipmentId)
    {
        $this->permission($request, 'transfer_add');
        DB::transaction(function () use ($request, $shipmentId) {
            $shipment = DB::table('branch_shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            abort_unless($shipment, 404);
            if ($shipment->status !== 'allocated') $this->fail('Only allocated stock can be dispatched.');
            $line = DB::table('branch_request_lines')->where('id', $shipment->branch_request_line_id)->first();
            $order = DB::table('branch_requests')->where('id', $line->branch_request_id)->first();
            WarehouseAccess::assertAllows($order->from_warehouse_id);
            $allocations = DB::table('stock_reservations')->where('owner_type', 'branch_shipment')
                ->where('owner_id', $shipmentId)->where('status', 'active')->lockForUpdate()->get();
            if ($allocations->isEmpty()) $this->fail('This shipment has no active allocation.');
            foreach ($allocations as $allocation) {
                if ($allocation->product_batch_id && DB::table('product_batches')
                    ->where('id', $allocation->product_batch_id)
                    ->whereDate('expiry_date', '<', now()->toDateString())->exists()) {
                    $this->fail('An allocated lot has expired; cancel and reallocate the shipment.');
                }
            }
            ReservationService::close('branch_shipment', $shipmentId, 'consumed',
                $request->user('api')->id, 'Dispatched to branch');
            StockLedger::during('branch_dispatch', 'BranchShipment', $shipmentId, function () use ($allocations, $order, $line, $shipmentId) {
                foreach ($allocations as $allocation) {
                    if ($allocation->product_batch_id) {
                        BatchService::allocateFromBatch($allocation->product_batch_id,
                            $order->from_warehouse_id, $allocation->quantity,
                            'branch_dispatch', 'BranchShipment', $shipmentId);
                    } else {
                        if ($allocation->product_serial_id) {
                            $serial = DB::table('product_serials')->where('id', $allocation->product_serial_id)
                                ->lockForUpdate()->first();
                            if (! $serial || $serial->status !== 'in_stock'
                                || (int) $serial->warehouse_id !== (int) $order->from_warehouse_id) {
                                $this->fail('An allocated serial is no longer in the source warehouse.');
                            }
                            DB::table('product_serials')->where('id', $serial->id)->update([
                                'status' => 'in_transit', 'warehouse_id' => null, 'updated_at' => now(),
                            ]);
                            $this->serialEvent($serial->id, 'dispatched', $order->from_warehouse_id, $shipmentId);
                        }
                        $stock = product_warehouse::ensure($line->product_id,
                            $order->from_warehouse_id, $line->product_variant_id);
                        $stock->qte -= (float) $allocation->quantity;
                        $stock->save();
                    }
                    DB::table('branch_shipment_items')->insert([
                        'branch_shipment_id' => $shipmentId,
                        'product_batch_id' => $allocation->product_batch_id,
                        'product_serial_id' => $allocation->product_serial_id,
                        'quantity' => $allocation->quantity, 'received_qte' => 0,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
            DB::table('branch_shipments')->where('id', $shipmentId)->update([
                'status' => 'in_transit', 'dispatched_by' => $request->user('api')->id,
                'dispatched_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('branch_requests')->where('id', $order->id)->update([
                'status' => 'in_transit', 'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    /** A shortage remains in transit and can be accepted on a later delivery. */
    public function receive(Request $request, $shipmentId)
    {
        $this->permission($request, 'transfer_edit');
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer',
            'items.*.received_qte' => 'required|numeric|min:0',
            'discrepancy_notes' => 'nullable|string|max:5000',
        ]);
        DB::transaction(function () use ($request, $shipmentId) {
            $shipment = DB::table('branch_shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            abort_unless($shipment, 404);
            if (! in_array($shipment->status, ['in_transit', 'discrepancy'], true)) {
                $this->fail('Only dispatched stock can be received.');
            }
            $line = DB::table('branch_request_lines')->where('id', $shipment->branch_request_line_id)->first();
            $order = DB::table('branch_requests')->where('id', $line->branch_request_id)->first();
            WarehouseAccess::assertAllows($order->to_warehouse_id);
            $items = DB::table('branch_shipment_items')->where('branch_shipment_id', $shipmentId)
                ->lockForUpdate()->get()->keyBy('id');
            $returnQc = $order->kind === 'pullout' && (bool) DB::table('products')
                ->where('id', $line->product_id)->value('requires_qc');
            $submitted = collect($request->items)->keyBy('id');
            if ($items->count() !== $submitted->count()
                || $items->keys()->diff($submitted->keys())->isNotEmpty()) {
                $this->fail('Report a received quantity for every dispatched source line.');
            }
            $shortage = false;
            StockLedger::during('branch_receipt', 'BranchShipment', $shipmentId, function () use (
                $items, $submitted, $order, $line, $shipmentId, $returnQc, &$shortage
            ) {
                foreach ($items as $item) {
                    $take = (float) $submitted[$item->id]['received_qte'];
                    $remaining = (float) $item->quantity - (float) $item->received_qte;
                    if ($take > $remaining + 0.000001 || ($item->product_serial_id && $take != round($take))) {
                        $this->fail('Received quantity exceeds dispatched source quantity.');
                    }
                    if ($take > 0) {
                        if ($item->product_batch_id && ! $returnQc) {
                            BatchService::receive($item->product_batch_id, $order->to_warehouse_id,
                                $take, 'branch_receipt', 'BranchShipment', $shipmentId);
                        } elseif (! $item->product_batch_id) {
                            if ($item->product_serial_id) {
                                $serial = DB::table('product_serials')
                                    ->where('id', $item->product_serial_id)->lockForUpdate()->first();
                                if (! $serial || $serial->status !== 'in_transit') {
                                    $this->fail('This serial is no longer in transit.');
                                }
                                DB::table('product_serials')->where('id', $serial->id)->update([
                                    'status' => $returnQc ? 'qc_hold' : 'in_stock',
                                    'warehouse_id' => $order->to_warehouse_id,
                                    'updated_at' => now(),
                                ]);
                                $this->serialEvent($serial->id,
                                    $returnQc ? 'branch_return_qc_hold' : 'branch_received',
                                    $order->to_warehouse_id, $shipmentId);
                            }
                            if (! $returnQc) {
                                $stock = product_warehouse::ensure($line->product_id,
                                    $order->to_warehouse_id, $line->product_variant_id);
                                $stock->qte += $take;
                                $stock->save();
                            }
                        }
                        DB::table('branch_shipment_items')->where('id', $item->id)->update([
                            'received_qte' => (float) $item->received_qte + $take,
                            'qc_status' => $returnQc ? 'pending' : $item->qc_status,
                            'updated_at' => now(),
                        ]);
                    }
                    if ((float) $item->received_qte + $take + 0.000001 < (float) $item->quantity) {
                        $shortage = true;
                    }
                }
            });
            if ($shortage && ! $request->filled('discrepancy_notes')) {
                $this->fail('Record the discrepancy before accepting a short delivery.');
            }
            $awaitingQc = $returnQc && DB::table('branch_shipment_items')
                ->where('branch_shipment_id', $shipmentId)
                ->whereRaw('received_qte > qc_passed_qte + qc_failed_qte')->exists();
            DB::table('branch_shipments')->where('id', $shipmentId)->update([
                'status' => $shortage ? 'discrepancy' : ($awaitingQc ? 'awaiting_qc' : 'received'),
                'received_by' => $request->user('api')->id,
                'received_at' => now(),
                'discrepancy_notes' => $shortage ? $request->discrepancy_notes : null,
                'updated_at' => now(),
            ]);
            $open = DB::table('branch_shipments as sh')
                ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
                ->where('l.branch_request_id', $order->id)
                ->whereNotIn('sh.status', ['received', 'cancelled'])->exists();
            $requested = (float) DB::table('branch_request_lines')
                ->where('branch_request_id', $order->id)->sum('requested_qte');
            $accepted = (float) DB::table('branch_shipment_items as i')
                ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
                ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
                ->where('l.branch_request_id', $order->id)->sum('i.received_qte');
            DB::table('branch_requests')->where('id', $order->id)->update([
                'status' => $open ? ($awaitingQc && ! $shortage ? 'awaiting_qc' : 'in_transit')
                    : ($accepted + 0.000001 < $requested ? 'partial' : 'received'),
                'updated_at' => now(),
            ]);
        }, 5);
        return response()->json(['success' => true]);
    }

    /** Release returned QC-marked stock only after a separate inspection. */
    public function decideReturnQc(Request $request, $shipmentId)
    {
        $this->permission($request, 'goods_receipt_qc_approve');
        $request->validate([
            'item_id' => 'required|integer',
            'passed_qte' => 'required|numeric|min:0',
            'failed_qte' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ]);
        if ((float) $request->passed_qte + (float) $request->failed_qte <= 0) {
            $this->fail('Inspect a positive received quantity.');
        }
        if ((float) $request->failed_qte > 0 && ! $request->filled('notes')) {
            $this->fail('Record why returned goods failed inspection.');
        }
        DB::transaction(function () use ($request, $shipmentId) {
            $shipment = DB::table('branch_shipments')->where('id', $shipmentId)->lockForUpdate()->first();
            abort_unless($shipment, 404);
            if (! in_array($shipment->status, ['awaiting_qc', 'discrepancy'], true)) {
                $this->fail('This return has no received stock awaiting QC.');
            }
            $line = DB::table('branch_request_lines')->where('id', $shipment->branch_request_line_id)->first();
            $order = DB::table('branch_requests')->where('id', $line->branch_request_id)->first();
            if ($order->kind !== 'pullout' || ! DB::table('products')->where('id', $line->product_id)
                ->value('requires_qc')) {
                $this->fail('QC release is only for returned products marked QC required.');
            }
            WarehouseAccess::assertAllows($order->to_warehouse_id);
            if ((int) $shipment->received_by === (int) $request->user('api')->id) {
                $this->fail('A separate QC approver must inspect this return.');
            }
            $item = DB::table('branch_shipment_items')->where('id', $request->item_id)
                ->where('branch_shipment_id', $shipmentId)->lockForUpdate()->first();
            abort_unless($item, 404);
            $pending = (float) $item->received_qte - (float) $item->qc_passed_qte
                - (float) $item->qc_failed_qte;
            $deciding = (float) $request->passed_qte + (float) $request->failed_qte;
            if ($deciding > $pending + 0.000001 || $pending <= 0) {
                $this->fail('QC decision exceeds received, uninspected stock.');
            }
            if ($item->product_serial_id && ($deciding != 1 ||
                ! in_array((float) $request->passed_qte, [0.0, 1.0], true))) {
                $this->fail('Inspect a returned serial as one whole unit.');
            }
            StockLedger::during('branch_return_qc_pass', 'BranchShipment', $shipmentId,
                function () use ($item, $line, $order, $request, $shipmentId) {
                    $passed = (float) $request->passed_qte;
                    if ($passed <= 0) return;
                    if ($item->product_batch_id) {
                        BatchService::receive($item->product_batch_id, $order->to_warehouse_id,
                            $passed, 'branch_return_qc_pass', 'BranchShipment', $shipmentId);
                    } else {
                        if ($item->product_serial_id) {
                            $serial = DB::table('product_serials')->where('id', $item->product_serial_id)
                                ->lockForUpdate()->first();
                            if (! $serial || $serial->status !== 'qc_hold'
                                || (int) $serial->warehouse_id !== (int) $order->to_warehouse_id) {
                                $this->fail('The returned serial is no longer held for QC.');
                            }
                            DB::table('product_serials')->where('id', $serial->id)->update([
                                'status' => 'in_stock', 'updated_at' => now(),
                            ]);
                            $this->serialEvent($serial->id, 'branch_return_qc_passed',
                                $order->to_warehouse_id, $shipmentId);
                        }
                        $stock = product_warehouse::ensure($line->product_id,
                            $order->to_warehouse_id, $line->product_variant_id);
                        $stock->qte += $passed;
                        $stock->save();
                    }
                });
            if ($item->product_serial_id && (float) $request->failed_qte === 1.0) {
                DB::table('product_serials')->where('id', $item->product_serial_id)
                    ->where('status', 'qc_hold')->update(['status' => 'qc_rejected', 'updated_at' => now()]);
                $this->serialEvent($item->product_serial_id, 'branch_return_qc_failed',
                    $order->to_warehouse_id, $shipmentId);
            }
            DB::table('branch_shipment_qc_decisions')->insert([
                'branch_shipment_item_id' => $item->id,
                'passed_qte' => $request->passed_qte, 'failed_qte' => $request->failed_qte,
                'notes' => $request->notes, 'decided_by' => $request->user('api')->id,
                'created_at' => now(),
            ]);
            $passedTotal = (float) $item->qc_passed_qte + (float) $request->passed_qte;
            $failedTotal = (float) $item->qc_failed_qte + (float) $request->failed_qte;
            DB::table('branch_shipment_items')->where('id', $item->id)->update([
                'qc_passed_qte' => $passedTotal, 'qc_failed_qte' => $failedTotal,
                'qc_status' => $passedTotal + $failedTotal + 0.000001 < (float) $item->received_qte
                    ? 'partial' : ($failedTotal > 0 ? ($passedTotal > 0 ? 'partial' : 'failed') : 'passed'),
                'updated_at' => now(),
            ]);
            $pendingOther = DB::table('branch_shipment_items')
                ->where('branch_shipment_id', $shipmentId)
                ->whereRaw('received_qte > qc_passed_qte + qc_failed_qte')->exists();
            if (! $pendingOther) {
                $shortage = DB::table('branch_shipment_items')
                    ->where('branch_shipment_id', $shipmentId)
                    ->whereRaw('received_qte < quantity')->exists();
                DB::table('branch_shipments')->where('id', $shipmentId)->update([
                    'status' => $shortage ? 'discrepancy' : 'received', 'updated_at' => now(),
                ]);
                if (! $shortage) {
                    $open = DB::table('branch_shipments as s')
                        ->join('branch_request_lines as l', 'l.id', '=', 's.branch_request_line_id')
                        ->where('l.branch_request_id', $order->id)
                        ->whereNotIn('s.status', ['received', 'cancelled'])->exists();
                    if (! $open) {
                        $requested = (float) DB::table('branch_request_lines')
                            ->where('branch_request_id', $order->id)->sum('requested_qte');
                        $received = (float) DB::table('branch_shipment_items as i')
                            ->join('branch_shipments as s', 's.id', '=', 'i.branch_shipment_id')
                            ->join('branch_request_lines as l', 'l.id', '=', 's.branch_request_line_id')
                            ->where('l.branch_request_id', $order->id)->sum('i.received_qte');
                        DB::table('branch_requests')->where('id', $order->id)->update([
                            'status' => $received + 0.000001 < $requested ? 'partial' : 'received',
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }, 5);
        return response()->json(['success' => true]);
    }

    private function serialEvent($serialId, $event, $warehouseId, $shipmentId)
    {
        DB::table('product_serial_events')->insert([
            'product_serial_id' => $serialId, 'event' => $event,
            'warehouse_id' => $warehouseId,
            'reference_type' => 'BranchShipment', 'reference_id' => $shipmentId,
            'user_id' => auth('api')->id(), 'created_at' => now(),
        ]);
    }
}
