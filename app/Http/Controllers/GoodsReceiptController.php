<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptDetail;
use App\Models\ProductBatch;
use App\Models\product_warehouse;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\Unit;
use App\utils\BatchService;
use App\utils\QcService;
use App\utils\SerialService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\utils\DocumentSequence;
use App\utils\StockLedger;

/**
 * Goods receipt (GRN).
 *
 * A purchase raised as "ordered" or "pending" holds no stock. A receipt
 * records what physically arrived against it — in full or across several
 * deliveries — and only then does stock move.
 *
 * Purchases saved directly as "received" keep their existing behaviour and
 * are not part of this flow.
 */
class GoodsReceiptController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoodsReceipt::class);

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
            'SortType' => 'nullable|in:asc,desc',
        ]);

        $perPage = (int) $request->input('limit', 10);

        $query = GoodsReceipt::where('deleted_at', '=', null)
            ->when($request->filled('status'), function ($q) use ($request) {
                return $q->where('status', $request->status);
            })
            ->when($request->filled('purchase_id'), function ($q) use ($request) {
                return $q->where('purchase_id', $request->purchase_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where('Ref', 'like', '%' . $request->search . '%')
                         ->orWhere('delivery_note', 'like', '%' . $request->search . '%');
            });

        $totalRows = $query->count();

        $receipts = $query->with(['purchase', 'warehouse', 'user'])
            ->orderBy('id', $request->input('SortType', 'desc'))
            ->offset(($request->input('page', 1) - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $data = [];
        foreach ($receipts as $receipt) {
            $data[] = [
                'id' => $receipt->id,
                'Ref' => $receipt->Ref,
                'date' => $receipt->date,
                'purchase_ref' => $receipt->purchase ? $receipt->purchase->Ref : '—',
                'purchase_id' => $receipt->purchase_id,
                'warehouse' => $receipt->warehouse ? $receipt->warehouse->name : '—',
                'delivery_note' => $receipt->delivery_note,
                'user' => $receipt->user ? $receipt->user->username : '—',
                'status' => $receipt->status,
            ];
        }

        return response()->json(['goods_receipts' => $data, 'totalRows' => $totalRows]);
    }

    /**
     * Purchases that still have quantity outstanding, so the user can pick
     * what to receive against.
     */
    public function awaiting(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoodsReceipt::class);

        $purchases = Purchase::where('deleted_at', '=', null)
            ->whereIn('statut', ['ordered', 'pending'])
            ->where('approval_status', '!=', 'rejected')
            // An order closed short is a decision already taken; leaving it here
            // would keep counting goods nobody is going to deliver.
            ->whereNull('short_closed_at')
            ->with(['provider', 'warehouse'])
            ->orderBy('id', 'desc')
            ->get();

        $data = [];
        foreach ($purchases as $purchase) {
            $outstanding = PurchaseDetail::where('purchase_id', $purchase->id)
                ->whereRaw('quantity > received_qte')
                ->count();

            if ($outstanding === 0) {
                continue;
            }

            $data[] = [
                'id' => $purchase->id,
                'Ref' => $purchase->Ref,
                'date' => $purchase->date,
                'supplier' => $purchase->provider ? $purchase->provider->name : '—',
                'warehouse' => $purchase->warehouse ? $purchase->warehouse->name : '—',
                'warehouse_id' => $purchase->warehouse_id,
                'statut' => $purchase->statut,
                'approval_status' => $purchase->approval_status,
                'outstanding_lines' => $outstanding,
            ];
        }

        return response()->json(['purchases' => $data]);
    }

    /**
     * Draft a receipt against a purchase, pre-filled with everything still
     * outstanding on that order.
     */
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', GoodsReceipt::class);

        $request->validate(['purchase_id' => 'required|integer|exists:purchases,id']);

        $purchase = Purchase::with('provider')->findOrFail($request->purchase_id);

        $lines = [];
        $details = PurchaseDetail::where('purchase_id', $purchase->id)
            ->with('product')
            ->get();

        foreach ($details as $detail) {
            $outstanding = (float) $detail->quantity - (float) $detail->received_qte;

            if ($outstanding <= 0) {
                continue;
            }

            $lines[] = [
                'purchase_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'product_variant_id' => $detail->product_variant_id,
                'purchase_unit_id' => $detail->purchase_unit_id,
                'code' => $detail->product ? $detail->product->code : null,
                'name' => $detail->product ? $detail->product->name : '(deleted product)',
                'ordered_qte' => (float) $detail->quantity,
                'already_received' => (float) $detail->received_qte,
                'outstanding' => $outstanding,
                // Tells the form whether to ask for a lot number and expiry.
                'has_batches' => BatchService::isTracked($detail->product_id),
                // And whether to ask for serials: one per base unit received.
                'has_serials' => \App\utils\SerialService::isTracked($detail->product_id),
                'requires_qc' => QcService::isRequired($detail->product_id),
                'base_units_per' => $this->toBaseQty($detail, 1),
            ];
        }

        return response()->json([
            'purchase' => [
                'id' => $purchase->id,
                'Ref' => $purchase->Ref,
                'supplier' => $purchase->provider ? $purchase->provider->name : '—',
                'warehouse_id' => $purchase->warehouse_id,
                'approval_status' => $purchase->approval_status,
            ],
            'details' => $lines,
        ]);
    }

    /**
     * Record and post a receipt. Stock moves here.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', GoodsReceipt::class);

        $request->validate([
            'purchase_id' => 'required|integer|exists:purchases,id',
            'date' => 'required|date',
            'delivery_note' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
            'details' => 'required|array|min:1',
            'details.*.purchase_detail_id' => 'required|integer|exists:purchase_details,id',
            'details.*.received_qte' => 'required|numeric|min:0',
            'details.*.rejected_qte' => 'nullable|numeric|min:0',
            // Only required for batch-tracked products; checked per line below.
            'details.*.batch_number' => 'nullable|string|max:100',
            'details.*.expiry_date' => 'nullable|date',
            'details.*.manufacture_date' => 'nullable|date',
        ]);

        $purchase = Purchase::findOrFail($request->purchase_id);

        if ($purchase->approval_status === 'pending') {
            return response()->json([
                'message' => 'This purchase is awaiting approval and cannot be received yet.',
            ], 422);
        }

        if ($purchase->approval_status === 'rejected') {
            return response()->json([
                'message' => 'This purchase was rejected and cannot be received.',
            ], 422);
        }

        // Somebody decided the balance was never coming. If it turns up after
        // all, that is a new order rather than a late delivery against a closed
        // one -- otherwise stock slips in behind a decision nobody revisited.
        if ($purchase->short_closed_at !== null) {
            return response()->json([
                'message' => 'This order was closed short on ' . $purchase->short_closed_at
                    . ' (' . $purchase->short_close_reason . '). Raise a new order for anything that still arrives.',
            ], 422);
        }

        $receipt = \DB::transaction(function () use ($request, $purchase) {
            $receipt = GoodsReceipt::create([
                'Ref' => $this->getNumberOrder(),
                'date' => $request->date,
                'purchase_id' => $purchase->id,
                'warehouse_id' => $purchase->warehouse_id,
                'user_id' => Auth::user()->id,
                'status' => 'received',
                'delivery_note' => $request->delivery_note,
                'notes' => $request->notes,
                'received_at' => Carbon::now(),
            ]);

            $counted = 0;
            $pendingQc = false;
            foreach ($request['details'] as $line) {
                $received = (float) $line['received_qte'];
                $rejected = (float) ($line['rejected_qte'] ?? 0);

                if ($received <= 0 && $rejected <= 0) {
                    continue;
                }
                $counted++;

                $detail = PurchaseDetail::where('id', $line['purchase_detail_id'])
                    ->where('purchase_id', $purchase->id)
                    ->lockForUpdate()
                    ->first();

                if (! $detail) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'details' => ['One of the lines does not belong to this purchase.'],
                    ]);
                }

                $outstanding = (float) $detail->quantity - (float) $detail->received_qte;

                // What was counted at the door, accepted or turned away, is
                // out of what is still to come: 5 taken and 99 refused on an
                // order of 10 was saved (DATATEST K71).
                if ($received + $rejected > $outstanding + 0.000001) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'details' => [sprintf(
                            'Cannot receive %s and reject %s of line %d: only %s is still outstanding.',
                            $received, $rejected, $detail->id, $outstanding
                        )],
                    ]);
                }

                $needsQc = $received > 0 && QcService::isRequired($detail->product_id);
                $pendingQc = $pendingQc || $needsQc;
                $receiptLine = GoodsReceiptDetail::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'product_variant_id' => $detail->product_variant_id,
                    'purchase_unit_id' => $detail->purchase_unit_id,
                    'ordered_qte' => $detail->quantity,
                    'received_qte' => $received,
                    'rejected_qte' => $rejected,
                    'notes' => $line['notes'] ?? null,
                    'qc_status' => $needsQc ? 'pending' : 'not_required',
                    'qc_passed_qte' => $needsQc ? 0 : $received,
                ]);

                if ($received > 0) {
                    if (BatchService::isTracked($detail->product_id)) {
                        // A batch-tracked product must arrive with a lot number,
                        // otherwise the stock could never be traced or expired.
                        if (empty($line['batch_number'])) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'batch' => [sprintf(
                                    'Line %d is batch-tracked and needs a batch number.',
                                    $detail->id
                                )],
                            ]);
                        }

                        $batch = BatchService::resolveBatch([
                            'product_id' => $detail->product_id,
                            'product_variant_id' => $detail->product_variant_id,
                            'batch_number' => $line['batch_number'],
                            'expiry_date' => $line['expiry_date'] ?? null,
                            'manufacture_date' => $line['manufacture_date'] ?? null,
                            'provider_id' => $purchase->provider_id,
                            'cost' => $detail->cost,
                            'source_receipt_detail_id' => $receiptLine->id,
                        ]);

                        // The lot identity exists while held, but only a QC
                        // release may lift sellable stock for flagged goods.
                        if (! $needsQc) {
                            BatchService::receive(
                                $batch->id,
                                $purchase->warehouse_id,
                                $this->toBaseQty($detail, $received),
                                'receipt',
                                'GoodsReceipt',
                                $receipt->id
                            );
                        }
                    } else {
                        // Serial-tracked: each unit arriving is named.
                        if (SerialService::isTracked($detail->product_id)) {
                            SerialService::receive($detail->product_id, $detail->product_variant_id,
                                $purchase->warehouse_id, $line['serials'] ?? [], $this->toBaseQty($detail, $received),
                                'GoodsReceipt', $receipt->id, $receiptLine->id, $needsQc ? 'qc_hold' : 'in_stock');
                        }
                        if (! $needsQc) {
                            $this->addToStock($purchase->warehouse_id, $detail, $received, $receiptLine->id);
                        }
                    }

                    $detail->received_qte = (float) $detail->received_qte + $received;
                    $detail->save();
                }
            }

            // A receipt that took nothing in and turned nothing away is not a
            // delivery (DATATEST K71); the transaction undoes its header.
            if ($counted === 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'details' => ['Enter what arrived: receive or reject at least one item.'],
                ]);
            }

            if ($pendingQc) {
                $receipt->status = 'pending_qc';
                $receipt->save();
            }

            $this->refreshPurchaseStatus($purchase);

            // The buyer hears it arrived, and whether anything is still owed.
            \App\utils\ActivityNotifier::goodsReceived($purchase->id);

            return $receipt;
        }, 10);

        return response()->json([
            'success' => true,
            'id' => $receipt->id,
            'Ref' => $receipt->Ref,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', GoodsReceipt::class);

        $receipt = GoodsReceipt::with(['purchase', 'warehouse', 'user'])->findOrFail($id);

        $lines = [];
        foreach ($receipt->details()->with(['product', 'qcDecider'])->get() as $line) {
            $lines[] = [
                'id' => $line->id,
                'code' => $line->product ? $line->product->code : null,
                'name' => $line->product ? $line->product->name : '(deleted product)',
                'ordered_qte' => (float) $line->ordered_qte,
                'received_qte' => (float) $line->received_qte,
                'rejected_qte' => (float) $line->rejected_qte,
                'notes' => $line->notes,
                'qc_status' => $line->qc_status,
                'qc_passed_qte' => (float) $line->qc_passed_qte,
                'qc_failed_qte' => (float) $line->qc_failed_qte,
                'qc_decided_by' => $line->qc_decided_by,
                'qc_decider' => $line->qcDecider ? $line->qcDecider->username : null,
                'qc_decided_at' => optional($line->qc_decided_at)->toDateTimeString(),
                'qc_notes' => $line->qc_notes,
                'qc_dispositions' => \DB::table('goods_receipt_qc_dispositions as q')
                    ->leftJoin('users as requester', 'requester.id', '=', 'q.requested_by')
                    ->leftJoin('users as approver', 'approver.id', '=', 'q.decided_by')
                    ->leftJoin('users as executor', 'executor.id', '=', 'q.executed_by')
                    ->where('q.goods_receipt_detail_id', $line->id)->orderBy('q.id')
                    ->get(['q.id', 'q.action', 'q.quantity', 'q.status', 'q.reason',
                        'q.serial_numbers',
                        'q.requested_by', 'requester.username as requester',
                        'approver.username as approver', 'q.decision_reason',
                        'q.created_at', 'q.decided_at', 'q.execution_reference',
                        'q.execution_note', 'q.executed_at',
                        'executor.username as executor']),
                'qc_custody' => \DB::table('qc_rejected_custody')
                    ->where('source_type', 'goods_receipt_detail')->where('source_id', $line->id)
                    ->orderBy('id')->get(['id', 'original_quantity', 'remaining_quantity',
                        'location', 'status', 'product_serial_id'])
                    ->map(function ($custody) {
                        $custody->events = \DB::table('qc_custody_events as e')
                            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
                            ->where('e.custody_id', $custody->id)->orderBy('e.id')
                            ->get(['e.event', 'e.quantity', 'e.from_location', 'e.to_location',
                                'e.reference_type', 'e.reference_id', 'e.note',
                                'e.created_at', 'u.username as actor']);
                        return $custody;
                    }),
                'qc_disposition_remaining' => max(0, (float) $line->qc_failed_qte -
                    (float) \DB::table('goods_receipt_qc_dispositions')
                        ->where('goods_receipt_detail_id', $line->id)
                        ->whereIn('status', ['pending', 'approved', 'executed'])->sum('quantity')),
                'qc_failed_serials' => SerialService::isTracked($line->product_id)
                    ? \DB::table('product_serials')->where('source_receipt_detail_id', $line->id)
                        ->where('status', 'qc_rejected')->orderBy('id')->pluck('serial_number')->all()
                    : [],
                'qc_serials' => $line->qc_status === 'pending' && SerialService::isTracked($line->product_id)
                    ? \DB::table('product_serials')->where('source_receipt_detail_id', $line->id)
                        ->orderBy('id')->pluck('serial_number')->all() : [],
                'base_units_per' => $this->toBaseQty(PurchaseDetail::findOrFail($line->purchase_detail_id), 1),
            ];
        }

        return response()->json([
            'goods_receipt' => [
                'id' => $receipt->id,
                'Ref' => $receipt->Ref,
                'date' => $receipt->date,
                'status' => $receipt->status,
                'delivery_note' => $receipt->delivery_note,
                'purchase_ref' => $receipt->purchase ? $receipt->purchase->Ref : '—',
                'warehouse' => $receipt->warehouse ? $receipt->warehouse->name : '—',
                'user' => $receipt->user ? $receipt->user->username : '—',
                'notes' => $receipt->notes,
            ],
            'details' => $lines,
        ]);
    }

    /** Release passed quantity into stock; failed quantity remains unavailable. */
    public function decideQc(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'approveQc', GoodsReceipt::class);
        $request->validate([
            'receipt_detail_id' => 'required|integer',
            'passed_qte' => 'required|numeric|min:0',
            'failed_qte' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'accepted_serials' => 'nullable|array',
            'accepted_serials.*' => 'string|max:100',
            'hold_location' => 'nullable|string|max:120',
        ]);

        \DB::transaction(function () use ($request, $id) {
            $receipt = GoodsReceipt::where('id', $id)->lockForUpdate()->firstOrFail();
            $line = GoodsReceiptDetail::where('goods_receipt_id', $receipt->id)
                ->where('id', $request->receipt_detail_id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'cancelled' || $line->qc_status !== 'pending') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qc' => ['This receipt line is not awaiting a QC decision.'],
                ]);
            }

            $passed = (float) $request->passed_qte;
            $failed = (float) $request->failed_qte;
            if (abs($passed + $failed - (float) $line->received_qte) > 0.000001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'qc' => ['Passed plus failed quantity must equal the received quantity.'],
                ]);
            }
            if ($failed > 0 && ! $request->filled('notes')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'notes' => ['Record a reason for goods that fail QC.'],
                ]);
            }
            if ($failed > 0 && ! trim((string) $request->hold_location)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'hold_location' => ['Record the physical QC hold location for failed goods.'],
                ]);
            }

            $purchaseDetail = PurchaseDetail::where('id', $line->purchase_detail_id)
                ->lockForUpdate()->firstOrFail();

            if (BatchService::isTracked($line->product_id)) {
                $batch = ProductBatch::where('source_receipt_detail_id', $line->id)->firstOrFail();
                if ($passed > 0) {
                    BatchService::receive($batch->id, $receipt->warehouse_id,
                        $this->toBaseQty($purchaseDetail, $passed), 'qc_release', 'GoodsReceipt', $receipt->id);
                }
            } else {
                if (SerialService::isTracked($line->product_id)) {
                    SerialService::inspectReceipt($line->id, $request->input('accepted_serials', []),
                        $this->toBaseQty($purchaseDetail, $passed), $receipt->id);
                }
                if ($passed > 0) {
                    $this->addToStock($receipt->warehouse_id, $purchaseDetail, $passed, $line->id);
                }
            }

            $purchaseDetail->received_qte = max(0, (float) $purchaseDetail->received_qte - $failed);
            $purchaseDetail->save();

            $line->fill([
                'qc_status' => $failed <= 0 ? 'passed' : ($passed <= 0 ? 'failed' : 'partial'),
                'qc_passed_qte' => $passed,
                'qc_failed_qte' => $failed,
                'qc_decided_by' => Auth::id(),
                'qc_decided_at' => now(),
                'qc_notes' => $request->notes,
            ]);
            $line->save();
            if ($failed > 0) {
                $serialIds = SerialService::isTracked($line->product_id)
                    ? \DB::table('product_serials')->where('source_receipt_detail_id', $line->id)
                        ->where('status', 'qc_rejected')->pluck('id')->all() : [];
                $batchId = BatchService::isTracked($line->product_id)
                    ? ProductBatch::where('source_receipt_detail_id', $line->id)->value('id') : null;
                \App\utils\QcCustodyService::recordFailure('goods_receipt_detail', $line->id,
                    $receipt->warehouse_id, $line->product_id, $line->product_variant_id,
                    $batchId, $serialIds, $this->toBaseQty($purchaseDetail, $failed),
                    trim($request->hold_location), Auth::id(), $request->notes);
            }

            if (! $receipt->details()->where('qc_status', 'pending')->exists()) {
                $receipt->status = 'received';
                $receipt->save();
            }
            $purchase = Purchase::findOrFail($receipt->purchase_id);
            $this->refreshPurchaseStatus($purchase);
        }, 10);

        return response()->json(['success' => true]);
    }

    /**
     * Reverse a posted receipt: take the stock back out and restore the
     * outstanding quantity on the order.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', GoodsReceipt::class);

        GoodsReceipt::findOrFail($id);
        if (\DB::table('goods_receipt_qc_dispositions as q')
            ->join('goods_receipt_details as d', 'd.id', '=', 'q.goods_receipt_detail_id')
            ->where('d.goods_receipt_id', $id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'qc' => ['This receipt has a disposition history. Keep it for audit and correct stock through a separate document.'],
            ]);
        }
        if (\DB::table('qc_rejected_custody as c')
            ->join('goods_receipt_details as d', 'd.id', '=', 'c.source_id')
            ->where('c.source_type', 'goods_receipt_detail')
            ->where('d.goods_receipt_id', $id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'qc' => ['This receipt has rejected-goods custody history. Keep it for audit and correct stock through a separate document.'],
            ]);
        }

        \DB::transaction(function () use ($id) {
            // Re-read under a lock: two undo requests at once each found the
            // receipt and both took its goods back off the shelf (DATATEST K25).
            $receipt = GoodsReceipt::where('id', $id)->lockForUpdate()->first();
            if (! $receipt || $receipt->status === 'cancelled') {
                abort(404, 'That goods receipt has already been undone.');
            }

            // Serials it brought in are forgotten -- unless one was sold since.
            \App\utils\SerialService::undoReceipt('GoodsReceipt', $receipt->id);

            // Batch-tracked lines are undone by replaying this receipt's own
            // ledger entries, so exactly the lots that were received come back
            // out — no guessing.
            BatchService::reverse('GoodsReceipt', $receipt->id);

            foreach ($receipt->details as $line) {
                if ((float) $line->received_qte <= 0) {
                    continue;
                }

                $detail = PurchaseDetail::where('id', $line->purchase_detail_id)
                    ->lockForUpdate()
                    ->first();

                if ($detail) {
                    if (! BatchService::isTracked($detail->product_id)) {
                        if ((float) $line->qc_passed_qte > 0) {
                            $this->addToStock($receipt->warehouse_id, $detail,
                                -1 * (float) $line->qc_passed_qte, $line->id);
                        }
                    }

                    $credited = (float) $line->received_qte - (float) $line->qc_failed_qte;
                    $detail->received_qte = max(0, (float) $detail->received_qte - $credited);
                    $detail->save();
                }
            }

            $purchase = Purchase::find($receipt->purchase_id);
            if ($purchase) {
                $this->refreshPurchaseStatus($purchase);
            }

            $receipt->update(['status' => 'cancelled']);
            $receipt->delete();
        }, 10);

        return response()->json(['success' => true]);
    }

    /**
     * Apply a quantity to stock in the purchase unit, converting to the base
     * unit exactly as PurchasesController does. A negative quantity reverses.
     */
    /** Quantity in the purchase unit converted to base units. */
    private function toBaseQty(PurchaseDetail $detail, $quantity)
    {
        $unit = Unit::find($detail->purchase_unit_id);

        if ($unit && $unit->operator == '/') {
            return $quantity / $unit->operator_value;
        }

        return $quantity * ($unit ? $unit->operator_value : 1);
    }

    private function addToStock($warehouseId, PurchaseDetail $detail, $quantity, $receiptLineId)
    {
        $unit = Unit::find($detail->purchase_unit_id);

        // The cost on the line is per purchase unit, while stock is held in base
        // units -- a box of twelve at 1,200 is 100 a piece. Feeding the wrong one
        // to the moving average would corrupt it quietly, so the line total is
        // divided by the base quantity it actually became.
        $baseQty = ($unit && $unit->operator == '/')
            ? $quantity / $unit->operator_value
            : $quantity * ($unit ? $unit->operator_value : 1);

        // Landed at the price actually paid: the line discount comes off
        // (DATATEST K43: the gross price moved the average).
        $netCost = (float) \App\utils\OrderTotals::line([
            'Unit_cost' => $detail->cost, 'quantity' => 1, 'discount' => $detail->discount,
            'discount_Method' => $detail->discount_method, 'tax_percent' => $detail->TaxNet, 'tax_method' => $detail->tax_method,
        ], 'Unit_cost')['net_price'];
        $unitCost = $baseQty > 0
            ? ($netCost * (float) $quantity) / $baseQty
            : $netCost;

        StockLedger::context('goods_receipt', 'GoodsReceiptDetail', $receiptLineId, [
            'unit_cost' => $unitCost,
            'notes' => 'Received against purchase ' . $detail->purchase_id,
        ]);

        $stock = product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $detail->product_id)
            ->when($detail->product_variant_id !== null, function ($q) use ($detail) {
                return $q->where('product_variant_id', $detail->product_variant_id);
            })
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            $stock = new product_warehouse;
            $stock->warehouse_id = $warehouseId;
            $stock->product_id = $detail->product_id;
            $stock->product_variant_id = $detail->product_variant_id;
            $stock->qte = 0;
        }

        if ($baseQty < 0 && (float) $stock->qte + $baseQty < -0.000001) {
            StockLedger::clear();
            throw \Illuminate\Validation\ValidationException::withMessages([
                'stock' => ['Some units from this receipt have already left the warehouse. Reverse those movements before cancelling it.'],
            ]);
        }

        if ($unit && $unit->operator == '/') {
            $stock->qte = (float) $stock->qte + ($quantity / $unit->operator_value);
        } else {
            $stock->qte = (float) $stock->qte + ($quantity * ($unit ? $unit->operator_value : 1));
        }

        $stock->save();

        StockLedger::clear();
    }

    /**
     * A purchase becomes "received" once every line is fully received, and
     * falls back to "pending" while anything is outstanding.
     */
    private function refreshPurchaseStatus(Purchase $purchase)
    {
        // A short close is a decision, not a quantity, so it is not something
        // this calculation may undo. Deleting a receipt from a closed order
        // would otherwise reopen it and put it back in the awaiting queue.
        if ($purchase->short_closed_at !== null) {
            return;
        }

        $pendingQc = GoodsReceiptDetail::where('qc_status', 'pending')
            ->whereHas('goodsReceipt', function ($query) use ($purchase) {
                $query->where('purchase_id', $purchase->id);
            })->exists();
        $outstanding = PurchaseDetail::where('purchase_id', $purchase->id)
            ->whereRaw('quantity > received_qte')
            ->count();

        $purchase->statut = ! $pendingQc && $outstanding === 0 ? 'received' : 'pending';
        $purchase->save();
    }

    public function getNumberOrder()
    {
        // Was: read the last row, split the reference, add one -- which handed
        // two simultaneous callers the same number. DocumentSequence claims it
        // atomically, and a unique index on Ref refuses a duplicate outright.
        return DocumentSequence::next('goods_receipts', 'GRN_', 0, 1111);
    }
}
