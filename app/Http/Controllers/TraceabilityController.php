<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Network-wide investigation of selected batch and serial tracked goods. */
class TraceabilityController extends BaseController
{
    public function overview(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        abort_if(WarehouseAccess::isRestricted(), 403, 'Network trace requires access to all warehouses.');

        $locations = DB::table('warehouses as w')
            ->leftJoin('product_warehouse as pw', function ($join) {
                $join->on('pw.warehouse_id', '=', 'w.id')->whereNull('pw.deleted_at');
            })
            ->whereNull('w.deleted_at')
            ->groupBy('w.id', 'w.code', 'w.name', 'w.site_type')
            ->orderBy('w.name')
            ->get(['w.id', 'w.code', 'w.name', 'w.site_type', DB::raw('COALESCE(SUM(pw.qte), 0) as on_hand')]);

        $salesVisible = DB::table('permissions')->where('name', 'Reports_sales')->exists()
            && $request->user('api')->can('Reports_sales', Sale::class);
        $sales = $salesVisible ? DB::table('sales')->whereNull('deleted_at')
            ->where('statut', 'completed')
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->groupBy('warehouse_id')
            ->select('warehouse_id', DB::raw('COUNT(*) as sale_count'),
                DB::raw('COALESCE(SUM(GrandTotal), 0) as sale_total'))
            ->get()->keyBy('warehouse_id') : collect();
        $lowStock = $this->lowStockQuery()
            ->select('pw.warehouse_id', DB::raw('COUNT(DISTINCT pw.product_id) as product_count'))
            ->groupBy('pw.warehouse_id')->pluck('product_count', 'warehouse_id');
        $reserved = DB::table('stock_reservations')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('warehouse_id', 'product_id', 'product_variant_id')
            ->get(['warehouse_id', 'product_id', 'product_variant_id', DB::raw('SUM(quantity) as qte')]);
        $expired = DB::table('batch_stock as bs')
            ->join('product_batches as b', 'b.id', '=', 'bs.product_batch_id')
            ->whereNull('b.deleted_at')->whereDate('b.expiry_date', '<', now()->toDateString())
            ->groupBy('bs.warehouse_id', 'b.product_id', 'b.product_variant_id')
            ->get(['bs.warehouse_id', 'b.product_id', 'b.product_variant_id', DB::raw('SUM(bs.qte) as qte')]);
        $key = fn ($warehouse, $product, $variant) => $warehouse . ':' . $product . ':' . ($variant === null ? '-' : $variant);
        $reservedByStock = $reserved->keyBy(fn ($row) => $key($row->warehouse_id, $row->product_id, $row->product_variant_id));
        $expiredByStock = $expired->keyBy(fn ($row) => $key($row->warehouse_id, $row->product_id, $row->product_variant_id));
        $inTransit = DB::table('branch_shipment_items as i')
            ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
            ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
            ->join('branch_requests as r', 'r.id', '=', 'l.branch_request_id')
            ->whereIn('sh.status', ['in_transit', 'discrepancy'])
            ->groupBy('r.to_warehouse_id')
            ->pluck(DB::raw('SUM(i.quantity - i.received_qte) as quantity'), 'r.to_warehouse_id');
        $returnQcHold = DB::table('branch_shipment_items as i')
            ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
            ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
            ->join('branch_requests as r', 'r.id', '=', 'l.branch_request_id')
            ->where('r.kind', 'pullout')
            ->whereRaw('i.received_qte > i.qc_passed_qte + i.qc_failed_qte')
            ->groupBy('r.to_warehouse_id')
            ->pluck(DB::raw('SUM(i.received_qte - i.qc_passed_qte - i.qc_failed_qte) as quantity'),
                'r.to_warehouse_id');
        $rejectedHold = DB::table('qc_rejected_custody')->where('status', 'held')
            ->groupBy('warehouse_id')
            ->pluck(DB::raw('SUM(remaining_quantity) as quantity'), 'warehouse_id');
        $stockTotals = [];
        foreach (DB::table('product_warehouse')->whereNull('deleted_at')
            ->get(['warehouse_id', 'product_id', 'product_variant_id', 'qte']) as $row) {
            $stockKey = $key($row->warehouse_id, $row->product_id, $row->product_variant_id);
            $held = (float) optional($reservedByStock->get($stockKey))->qte;
            $spoiled = (float) optional($expiredByStock->get($stockKey))->qte;
            if (! isset($stockTotals[$row->warehouse_id])) {
                $stockTotals[$row->warehouse_id] = ['reserved' => 0.0, 'available' => 0.0];
            }
            $stockTotals[$row->warehouse_id]['reserved'] += $held;
            $stockTotals[$row->warehouse_id]['available'] += max(0, (float) $row->qte - $held - $spoiled);
        }
        foreach ($locations as $location) {
            $location->reserved = $stockTotals[$location->id]['reserved'] ?? 0;
            $location->available_to_promise = $stockTotals[$location->id]['available'] ?? 0;
            $location->in_transit_to_location = (float) ($inTransit->get($location->id) ?? 0);
            $location->return_qc_hold = (float) ($returnQcHold->get($location->id) ?? 0);
            $location->rejected_qc_hold = (float) ($rejectedHold->get($location->id) ?? 0);
            $location->low_stock_products = (int) $lowStock->get($location->id, 0);
            $location->sales_30d_count = $salesVisible
                ? (int) optional($sales->get($location->id))->sale_count : null;
            $location->sales_30d_total = $salesVisible
                ? (float) optional($sales->get($location->id))->sale_total : null;
        }

        $pendingQc = DB::table('goods_receipt_details as d')
            ->join('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
            ->whereNull('gr.deleted_at')->where('d.qc_status', 'pending')
            ->count();
        $unknownLots = DB::table('product_batches as b')
            ->join('batch_stock as bs', 'bs.product_batch_id', '=', 'b.id')
            ->whereNull('b.deleted_at')->whereNull('b.source_receipt_detail_id')
            ->where('bs.qte', '>', 0)->distinct()->count('b.id');
        $unknownSerials = DB::table('product_serials')
            ->whereNull('source_receipt_detail_id')->count();
        $expiredLots = DB::table('product_batches as b')
            ->join('batch_stock as bs', 'bs.product_batch_id', '=', 'b.id')
            ->whereNull('b.deleted_at')->whereDate('b.expiry_date', '<', now()->toDateString())
            ->where('bs.qte', '>', 0)->distinct()->count('b.id');
        $lotMismatches = $this->lotBalanceQuery()->count();
        $pendingReturnQc = DB::table('branch_shipment_items as i')
            ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
            ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
            ->join('branch_requests as r', 'r.id', '=', 'l.branch_request_id')
            ->where('r.kind', 'pullout')
            ->whereRaw('i.received_qte > i.qc_passed_qte + i.qc_failed_qte')->count();
        $rejectedCustody = DB::table('qc_rejected_custody')
            ->where('status', 'held')->count();

        return response()->json([
            'locations' => $locations,
            'sales_visible' => $salesVisible,
            'exceptions' => [
                'pending_qc_lines' => $pendingQc,
                'pending_return_qc_items' => $pendingReturnQc,
                'rejected_custody_items' => $rejectedCustody,
                'source_unknown_lots' => $unknownLots,
                'source_unknown_serials' => $unknownSerials,
                'expired_lots_with_stock' => $expiredLots,
                'lot_balance_mismatches' => $lotMismatches,
            ],
        ]);
    }

    public function search(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        abort_if(WarehouseAccess::isRestricted(), 403, 'Network trace requires access to all warehouses.');

        $request->validate(['query' => 'required|string|min:2|max:100']);
        $term = trim($request->query('query'));
        $like = '%' . addcslashes($term, '%_\\') . '%';

        $batches = DB::table('product_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('goods_receipt_details as d', 'd.id', '=', 'b.source_receipt_detail_id')
            ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
            ->leftJoin('purchases as po', 'po.id', '=', 'gr.purchase_id')
            ->whereNull('b.deleted_at')
            ->where(function ($q) use ($like) {
                $q->where('b.batch_number', 'like', $like)
                    ->orWhere('p.code', 'like', $like)
                    ->orWhere('p.name', 'like', $like)
                    ->orWhere('gr.Ref', 'like', $like)
                    ->orWhere('po.Ref', 'like', $like)
                    ->orWhereIn('b.id', function ($saleLots) use ($like) {
                        $saleLots->select('bm.product_batch_id')
                            ->from('batch_movements as bm')
                            ->join('sales as s', function ($join) {
                                $join->on('s.id', '=', 'bm.reference_id')
                                    ->where('bm.reference_type', '=', 'Sale');
                            })
                            ->where('s.Ref', 'like', $like);
                    });
            })
            ->orderByDesc('b.id')->limit(40)
            ->get(['b.id', 'b.batch_number', 'b.source_receipt_detail_id',
                'p.code as product_code', 'p.name as product',
                'gr.Ref as receipt_ref', 'po.Ref as purchase_ref']);

        $serials = DB::table('product_serials as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('goods_receipt_details as d', 'd.id', '=', 's.source_receipt_detail_id')
            ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
            ->leftJoin('purchases as po', 'po.id', '=', 'gr.purchase_id')
            ->leftJoin('sales as sale', 'sale.id', '=', 's.sale_id')
            ->where(function ($q) use ($like) {
                $q->where('s.serial_number', 'like', $like)
                    ->orWhere('p.code', 'like', $like)
                    ->orWhere('p.name', 'like', $like)
                    ->orWhere('gr.Ref', 'like', $like)
                    ->orWhere('po.Ref', 'like', $like)
                    ->orWhere('sale.Ref', 'like', $like);
            })
            ->orderByDesc('s.id')->limit(40)
            ->get(['s.id', 's.serial_number', 's.status', 's.source_receipt_detail_id',
                'p.code as product_code', 'p.name as product',
                'gr.Ref as receipt_ref', 'po.Ref as purchase_ref', 'sale.Ref as sale_ref']);

        return response()->json(['batches' => $batches, 'serials' => $serials]);
    }

    /** Bounded, actionable rows behind the network exception totals. */
    public function exceptions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Product::class);
        abort_if(WarehouseAccess::isRestricted(), 403, 'Network trace requires access to all warehouses.');
        $request->validate([
            'type' => 'required|in:pending_qc_lines,pending_return_qc_items,rejected_custody_items,source_unknown_lots,source_unknown_serials,expired_lots_with_stock,lot_balance_mismatches,low_stock',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);
        $type = $request->query('type');
        $warehouseId = $request->query('warehouse_id');
        $limit = 100;

        if ($type === 'pending_qc_lines') {
            $rows = DB::table('goods_receipt_details as d')
                ->join('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
                ->join('products as p', 'p.id', '=', 'd.product_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'gr.warehouse_id')
                ->whereNull('gr.deleted_at')->where('d.qc_status', 'pending')
                ->when($warehouseId, fn ($q) => $q->where('gr.warehouse_id', $warehouseId))
                ->orderByDesc('d.id')->limit($limit)
                ->get(['d.id', 'gr.id as goods_receipt_id', 'gr.Ref as receipt_ref',
                    'p.code as product_code', 'p.name as product', 'w.name as warehouse',
                    'd.received_qte as quantity', 'gr.date']);
        } elseif ($type === 'pending_return_qc_items') {
            $rows = DB::table('branch_shipment_items as i')
                ->join('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
                ->join('branch_request_lines as l', 'l.id', '=', 'sh.branch_request_line_id')
                ->join('branch_requests as r', 'r.id', '=', 'l.branch_request_id')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->join('warehouses as w', 'w.id', '=', 'r.to_warehouse_id')
                ->where('r.kind', 'pullout')
                ->whereRaw('i.received_qte > i.qc_passed_qte + i.qc_failed_qte')
                ->when($warehouseId, fn ($q) => $q->where('r.to_warehouse_id', $warehouseId))
                ->orderByDesc('i.id')->limit($limit)
                ->get(['i.id', 'sh.Ref as shipment_ref', 'p.code as product_code',
                    'p.name as product', 'w.name as warehouse',
                    DB::raw('i.received_qte - i.qc_passed_qte - i.qc_failed_qte as quantity')]);
        } elseif ($type === 'rejected_custody_items') {
            $rows = DB::table('qc_rejected_custody as c')
                ->join('products as p', 'p.id', '=', 'c.product_id')
                ->join('warehouses as w', 'w.id', '=', 'c.warehouse_id')
                ->leftJoin('product_batches as b', 'b.id', '=', 'c.product_batch_id')
                ->leftJoin('product_serials as s', 's.id', '=', 'c.product_serial_id')
                ->leftJoin('goods_receipt_details as d', function ($join) {
                    $join->on('d.id', '=', 'c.source_id')
                        ->where('c.source_type', '=', 'goods_receipt_detail');
                })
                ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'd.goods_receipt_id')
                ->leftJoin('branch_shipment_items as i', function ($join) {
                    $join->on('i.id', '=', 'c.source_id')
                        ->where('c.source_type', '=', 'branch_shipment_item');
                })
                ->leftJoin('branch_shipments as sh', 'sh.id', '=', 'i.branch_shipment_id')
                ->where('c.status', 'held')
                ->when($warehouseId, fn ($q) => $q->where('c.warehouse_id', $warehouseId))
                ->orderByDesc('c.id')->limit($limit)
                ->get(['c.id', 'c.source_type', 'c.source_id', 'c.location',
                    'c.remaining_quantity as quantity', 'p.code as product_code',
                    'p.name as product', 'w.name as warehouse', 'b.batch_number',
                    's.serial_number', 'gr.Ref as receipt_ref', 'sh.Ref as shipment_ref']);
        } elseif ($type === 'source_unknown_serials') {
            $rows = DB::table('product_serials as s')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
                ->whereNull('s.source_receipt_detail_id')
                ->when($warehouseId, fn ($q) => $q->where('s.warehouse_id', $warehouseId))
                ->orderByDesc('s.id')->limit($limit)
                ->get(['s.id', 's.serial_number', 's.status', 'p.code as product_code',
                    'p.name as product', 'w.name as warehouse']);
        } elseif ($type === 'lot_balance_mismatches') {
            $rows = $this->lotBalanceQuery()
                ->orderByDesc('b.id')->limit($limit)
                ->get(['b.id', 'b.batch_number', 'p.code as product_code', 'p.name as product',
                    DB::raw('COALESCE(stock.stock_balance, 0) as stock_balance'),
                    DB::raw('COALESCE(movements.movement_balance, 0) as movement_balance')]);
        } elseif ($type === 'low_stock') {
            $rows = $this->lowStockQuery()
                ->join('warehouses as w', 'w.id', '=', 'pw.warehouse_id')
                ->when($warehouseId, fn ($q) => $q->where('pw.warehouse_id', $warehouseId))
                ->orderBy('pw.qte')->limit($limit)
                ->get(['p.id as product_id', 'p.code as product_code', 'p.name as product',
                    'w.name as warehouse', 'pw.qte as quantity', 'p.stock_alert as threshold']);
        } else {
            $rows = DB::table('product_batches as b')
                ->join('batch_stock as bs', 'bs.product_batch_id', '=', 'b.id')
                ->join('products as p', 'p.id', '=', 'b.product_id')
                ->join('warehouses as w', 'w.id', '=', 'bs.warehouse_id')
                ->whereNull('b.deleted_at')->where('bs.qte', '>', 0)
                ->when($warehouseId, fn ($q) => $q->where('bs.warehouse_id', $warehouseId))
                ->when($type === 'source_unknown_lots', fn ($q) => $q->whereNull('b.source_receipt_detail_id'))
                ->when($type === 'expired_lots_with_stock', fn ($q) => $q->whereDate('b.expiry_date', '<', now()->toDateString()))
                ->orderByDesc('b.id')->limit($limit)
                ->get(['b.id', 'b.batch_number', 'b.expiry_date', 'p.code as product_code',
                    'p.name as product', 'w.name as warehouse', 'bs.qte as quantity']);
        }

        return response()->json(['type' => $type, 'rows' => $rows,
            'limit' => $limit, 'may_have_more' => $rows->count() === $limit]);
    }

    private function lowStockQuery()
    {
        return DB::table('product_warehouse as pw')
            ->join('products as p', 'p.id', '=', 'pw.product_id')
            ->whereNull('pw.deleted_at')->whereNull('p.deleted_at')
            ->where('p.is_active', 1)->where('p.is_service', 0)
            ->where('p.stock_alert', '>', 0)
            ->whereColumn('pw.qte', '<=', 'p.stock_alert');
    }

    private function lotBalanceQuery()
    {
        $stock = DB::table('batch_stock')->select('product_batch_id',
            DB::raw('SUM(qte) as stock_balance'))->groupBy('product_batch_id');
        $movements = DB::table('batch_movements')->select('product_batch_id',
            DB::raw('SUM(qte) as movement_balance'))->groupBy('product_batch_id');

        return DB::table('product_batches as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_batch_id', '=', 'b.id'))
            ->leftJoinSub($movements, 'movements', fn ($join) => $join->on('movements.product_batch_id', '=', 'b.id'))
            ->whereNull('b.deleted_at')
            ->whereRaw('ABS(COALESCE(stock.stock_balance, 0) - COALESCE(movements.movement_balance, 0)) > 0.000001');
    }
}
