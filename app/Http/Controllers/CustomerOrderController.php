<?php

namespace App\Http\Controllers;

use App\utils\DocumentSequence;
use App\utils\CustomerOrderService;
use App\utils\ReservationService;
use App\utils\ShopPaymentService;
use App\utils\WarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/** Durable customer promises, including branch pre-orders which have no stock yet. */
class CustomerOrderController extends BaseController
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
        throw ValidationException::withMessages(['customer_order' => [$message]]);
    }

    public function index(Request $request)
    {
        $this->permission($request, 'Sales_view');
        $rows = DB::table('customer_orders as o')
            ->join('clients as c', 'c.id', '=', 'o.client_id')
            ->join('warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->when(WarehouseAccess::allowedIds() !== null,
                fn ($q) => $q->whereIn('o.warehouse_id', WarehouseAccess::allowedIds()))
            ->orderByDesc('o.id')->limit(100)
            ->get(['o.*', 'c.name as customer', 'w.name as warehouse']);
        return response()->json(['orders' => $rows]);
    }

    public function options(Request $request)
    {
        $this->permission($request, 'Sales_view');
        $warehouses = DB::table('warehouses')->whereNull('deleted_at')
            ->when(WarehouseAccess::allowedIds() !== null,
                fn ($q) => $q->whereIn('id', WarehouseAccess::allowedIds()))
            ->orderBy('name')->get(['id', 'name', 'site_type']);
        $clients = DB::table('clients')->whereNull('deleted_at')
            ->orderBy('name')->limit(500)->get(['id', 'name', 'code']);
        $products = DB::table('products')->whereNull('deleted_at')
            ->where('is_active', 1)->where('is_service', 0)
            ->orderBy('name')->limit(500)->get(['id', 'code', 'name', 'price']);
        $variants = DB::table('product_variants')->whereIn('product_id', $products->pluck('id'))
            ->orderBy('name')->get(['id', 'product_id', 'name']);
        return response()->json(compact('warehouses', 'clients', 'products', 'variants'));
    }

    public function show(Request $request, $id)
    {
        $this->permission($request, 'Sales_view');
        $order = DB::table('customer_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        WarehouseAccess::assertAllows($order->warehouse_id);
        $order->shop_payment = DB::table('shop_payment_sessions')
            ->where('customer_order_id', $id)->orderByDesc('id')
            ->first(['status', 'amount', 'refund_status', 'reference_id', 'session_id']);
        $order->lines = DB::table('customer_order_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.customer_order_id', $id)->orderBy('l.id')
            ->get(['l.*', 'p.code', 'p.name', 'p.price', 'p.unit_sale_id']);
        foreach ($order->lines as $line) {
            $line->reservations = DB::table('stock_reservations as r')
                ->leftJoin('product_batches as b', 'b.id', '=', 'r.product_batch_id')
                ->leftJoin('product_serials as s', 's.id', '=', 'r.product_serial_id')
                ->where('r.owner_type', 'customer_order_line')->where('r.owner_id', $line->id)
                ->get(['r.id', 'r.quantity', 'r.status', 'b.batch_number', 's.serial_number']);
        }
        return response()->json(['order' => $order]);
    }

    public function catalog(Request $request)
    {
        $this->permission($request, 'products_view');
        $request->validate(['warehouse_id' => 'required|integer|exists:warehouses,id',
            'limit' => 'nullable|integer|min:1|max:100', 'search' => 'nullable|string|max:80']);
        WarehouseAccess::assertAllows($request->warehouse_id);
        $term = trim((string) $request->query('search', ''));
        $products = DB::table('products')->whereNull('deleted_at')
            ->where('is_active', 1)->where('is_service', 0)
            ->when($term !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('code', 'like', '%' . addcslashes($term, '%_\\') . '%')
                ->orWhere('name', 'like', '%' . addcslashes($term, '%_\\') . '%')))
            ->orderBy('name')->limit($request->limit ?: 50)
            ->get(['id', 'code', 'name', 'price', 'has_batches', 'has_serials', 'updated_at']);
        foreach ($products as $product) {
            $product->available_to_promise = ReservationService::available(
                $request->warehouse_id, $product->id);
        }
        return response()->json(['warehouse_id' => (int) $request->warehouse_id,
            'products' => $products]);
    }

    public function store(Request $request)
    {
        $this->permission($request, 'Sales_add');
        $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'source_channel' => 'nullable|in:staff,web',
            'external_order_id' => 'required_if:source_channel,web|nullable|string|max:100',
            'allow_preorder' => 'nullable|boolean',
            'expected_supply_date' => 'nullable|date',
            'promise_date' => 'nullable|date',
            'deposit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.product_variant_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);
        WarehouseAccess::assertAllows($request->warehouse_id);
        $channel = $request->source_channel ?: 'staff';
        $externalId = $channel === 'web' ? $request->external_order_id : null;
        $payload = [
            'client_id' => (int) $request->client_id,
            'warehouse_id' => (int) $request->warehouse_id,
            'lines' => $request->lines,
            'allow_preorder' => (bool) $request->allow_preorder,
            'promise_date' => $request->promise_date,
            'expected_supply_date' => $request->expected_supply_date,
            'deposit_amount' => (float) ($request->deposit_amount ?: 0),
        ];
        $hash = hash('sha256', json_encode($payload));
        try {
            $id = DB::transaction(function () use ($request, $channel, $externalId, $hash) {
            if ($externalId) {
                $existing = DB::table('customer_orders')
                    ->where('source_channel', $channel)
                    ->where('external_order_id', $externalId)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->payload_hash !== $hash) {
                        $this->fail('That external order ID was already used with different details.');
                    }
                    return $existing->id;
                }
            }
            $id = DB::table('customer_orders')->insertGetId([
                'Ref' => DocumentSequence::next('customer_orders', 'CO-', 6),
                'client_id' => $request->client_id, 'warehouse_id' => $request->warehouse_id,
                'source_channel' => $channel, 'external_order_id' => $externalId,
                'payload_hash' => $hash, 'status' => 'pending_stock',
                'expected_supply_date' => $request->expected_supply_date,
                'promise_date' => $request->promise_date,
                'deposit_amount' => $request->deposit_amount ?: 0,
                'notes' => $request->notes, 'created_by' => $request->user('api')->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $allReserved = true;
            $seenProducts = [];
            foreach ($request->lines as $line) {
                $product = DB::table('products')->where('id', $line['product_id'])
                    ->whereNull('deleted_at')->first();
                if (! $product || $product->is_service) $this->fail('Only active stocked products can be ordered.');
                if (isset($line['product_variant_id']) && ! DB::table('product_variants')
                    ->where('id', $line['product_variant_id'])
                    ->where('product_id', $line['product_id'])->exists()) {
                    $this->fail('The selected variant does not belong to the product.');
                }
                if (isset($seenProducts[$line['product_id']])) {
                    $this->fail('Use one line per product in a customer order.');
                }
                $seenProducts[$line['product_id']] = true;
                $lineId = DB::table('customer_order_lines')->insertGetId([
                    'customer_order_id' => $id, 'product_id' => $line['product_id'],
                    'product_variant_id' => $line['product_variant_id'] ?? null,
                    'quantity' => $line['quantity'], 'status' => 'pending_stock',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                try {
                    ReservationService::reserve('customer_order_line', $lineId,
                        $request->warehouse_id, $line['product_id'],
                        $line['product_variant_id'] ?? null, $line['quantity'],
                        $request->user('api')->id);
                    DB::table('customer_order_lines')->where('id', $lineId)->update([
                        'status' => 'reserved', 'updated_at' => now(),
                    ]);
                } catch (ValidationException $e) {
                    if (! $request->allow_preorder) throw $e;
                    $allReserved = false;
                }
            }
            if ($allReserved) {
                DB::table('customer_orders')->where('id', $id)->update([
                    'status' => 'reserved', 'updated_at' => now(),
                ]);
            }
            CustomerOrderService::notifyStatus($id);
            return $id;
            }, 5);
        } catch (QueryException $e) {
            // Two workers may read the absent external ID together. The unique
            // index selects the winner; the loser returns that same order.
            if (! $externalId || ! in_array((int) ($e->errorInfo[1] ?? 0), [19, 1062], true)) {
                throw $e;
            }
            $existing = DB::table('customer_orders')->where('source_channel', $channel)
                ->where('external_order_id', $externalId)->first();
            if (! $existing) throw $e;
            if ($existing->payload_hash !== $hash) {
                $this->fail('That external order ID was already used with different details.');
            }
            $id = $existing->id;
        }
        return response()->json(['id' => $id,
            'status' => DB::table('customer_orders')->where('id', $id)->value('status')], 201);
    }

    public function allocate(Request $request, $lineId)
    {
        $this->permission($request, 'Sales_edit');
        DB::transaction(function () use ($request, $lineId) {
            $line = DB::table('customer_order_lines')->where('id', $lineId)->lockForUpdate()->first();
            abort_unless($line, 404);
            $order = DB::table('customer_orders')->where('id', $line->customer_order_id)
                ->lockForUpdate()->first();
            WarehouseAccess::assertAllows($order->warehouse_id);
            if ($line->status !== 'pending_stock' || $order->status === 'cancelled') {
                $this->fail('Only a pending-stock line can be reserved.');
            }
            ReservationService::reserve('customer_order_line', $lineId,
                $order->warehouse_id, $line->product_id, $line->product_variant_id,
                $line->quantity, $request->user('api')->id);
            DB::table('customer_order_lines')->where('id', $lineId)->update([
                'status' => 'reserved', 'updated_at' => now(),
            ]);
            if (! DB::table('customer_order_lines')->where('customer_order_id', $order->id)
                ->where('status', 'pending_stock')->exists()) {
                DB::table('customer_orders')->where('id', $order->id)->update([
                    'status' => 'reserved', 'updated_at' => now(),
                ]);
            }
            CustomerOrderService::notifyStatus($order->id);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, $id)
    {
        $this->permission($request, 'Sales_edit');
        $order = DB::table('customer_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        WarehouseAccess::assertAllows($order->warehouse_id);
        CustomerOrderService::cancel($id, $request->user('api')->id);
        return response()->json(['success' => true]);
    }

    public function verifyPayment(Request $request, $id)
    {
        $this->permission($request, 'Sales_edit');
        $order = DB::table('customer_orders')->where('id', $id)->first();
        abort_unless($order, 404);
        WarehouseAccess::assertAllows($order->warehouse_id);
        $payment = ShopPaymentService::latest($id);
        if (! $payment || ! in_array($payment->status, ['pending', 'review'], true)) {
            $this->fail('This order has no online payment awaiting verification.');
        }
        if (! $payment->session_id) {
            $this->fail('The provider session ID was not received. Check Xendit using reference '
                . $payment->reference_id . ' before staff resolve this payment.');
        }
        $payment = ShopPaymentService::refresh($payment, true);
        return response()->json(['status' => $payment->status,
            'reference_id' => $payment->reference_id]);
    }
}
