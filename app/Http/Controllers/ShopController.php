<?php

namespace App\Http\Controllers;

use App\utils\CustomerOrderService;
use App\utils\DocumentSequence;
use App\utils\ReservationService;
use App\utils\ShopPaymentService;
use App\utils\XenditCheckout;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Public CIMS storefront. Checkout uses the same stock reservations as staff sales. */
class ShopController extends Controller
{
    private function config()
    {
        return DB::table('settings')->orderBy('id')->first();
    }

    private function enabled()
    {
        $config = $this->config();
        if (! $config || ! $config->shop_enabled || ! $config->shop_warehouse_id
            || ! DB::table('warehouses')->where('id', $config->shop_warehouse_id)
                ->whereNull('deleted_at')->exists()) {
            abort(503, 'The online shop is not accepting orders yet.');
        }
        return $config;
    }

    private function fail($message)
    {
        throw ValidationException::withMessages(['checkout' => [$message]]);
    }

    private function productRows($warehouseId, $search = '', $categoryId = null, $page = 1)
    {
        $query = DB::table('products as p')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_sale_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->where('p.web_published', 1)->where('p.is_service', 0)
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $term = '%' . addcslashes($search, '%_\\') . '%';
                $sub->where('p.name', 'like', $term)->orWhere('p.code', 'like', $term);
            }))
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId));
        $count = (clone $query)->count();
        $rows = $query->orderBy('p.name')->offset(($page - 1) * 24)->limit(24)
            ->get(['p.id', 'p.code', 'p.name', 'p.price', 'p.image', 'p.note',
                'p.category_id', 'c.name as category', 'p.is_variant',
                'u.ShortName as unit', 'u.operator', 'u.operator_value']);
        $variants = DB::table('product_variants')->whereIn('product_id', $rows->pluck('id'))
            ->whereNull('deleted_at')->get(['id', 'product_id', 'name'])->groupBy('product_id');
        foreach ($rows as $row) {
            $file = $row->image ? basename(trim(explode(',', $row->image)[0])) : '';
            $row->image_url = $file && is_file(public_path('images/products/' . $file))
                ? asset('images/products/' . rawurlencode($file)) : null;
            $choices = $variants->get($row->id, collect());
            $row->variants = $choices->map(function ($variant) use ($row, $warehouseId) {
                return ['id' => $variant->id, 'name' => $variant->name,
                    'available' => ReservationService::available($warehouseId, $row->id, $variant->id)];
            })->values();
            if (! $row->is_variant || $row->variants->isEmpty()) {
                $row->available = ReservationService::available($warehouseId, $row->id);
            } else {
                $row->available = $row->variants->sum('available');
            }
            $row->unit_factor = $row->operator === '/' && (float) $row->operator_value > 0
                ? 1 / (float) $row->operator_value : max(1, (float) $row->operator_value);
            unset($row->image, $row->operator, $row->operator_value);
        }
        return ['products' => $rows, 'page' => $page,
            'has_more' => $count > $page * 24, 'total' => $count];
    }

    public function index()
    {
        $config = $this->config();
        $ready = $config && $config->shop_enabled && $config->shop_warehouse_id;
        $categories = $ready ? DB::table('categories as c')
            ->join('products as p', 'p.category_id', '=', 'c.id')
            ->whereNull('c.deleted_at')->whereNull('p.deleted_at')
            ->where('p.web_published', 1)->where('p.is_active', 1)->where('p.is_service', 0)
            ->distinct()->orderBy('c.name')->get(['c.id', 'c.name']) : collect();
        return view('shop.index', [
            'shopName' => $config && $config->shop_name ? $config->shop_name : 'NASPIN TECH',
            'ready' => (bool) $ready, 'categories' => $categories,
            'xenditReady' => XenditCheckout::configured(),
        ]);
    }

    public function catalog(Request $request)
    {
        $config = $this->enabled();
        $request->validate(['search' => 'nullable|string|max:80',
            'category_id' => 'nullable|integer', 'page' => 'nullable|integer|min:1|max:1000']);
        return response()->json($this->productRows($config->shop_warehouse_id,
            trim((string) $request->query('search', '')),
            $request->query('category_id'), (int) $request->query('page', 1)))
            ->header('Cache-Control', 'no-store');
    }

    public function checkout(Request $request)
    {
        $config = $this->enabled();
        $request->validate([
            'checkout_key' => 'required|uuid',
            'name' => 'required|string|min:2|max:192',
            'email' => 'required|email|max:192',
            'phone' => 'required|string|min:7|max:40',
            'address' => 'required|string|min:5|max:500',
            'city' => 'required|string|min:2|max:100',
            'notes' => 'nullable|string|max:1000',
            'payment_method' => 'required|in:cash_on_delivery,pay_in_store,xendit',
            'allow_preorder' => 'nullable|boolean',
            'items' => 'required|array|min:1|max:30',
            'items.*.product_id' => 'required|integer',
            'items.*.variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|gt:0|max:999',
        ]);
        $key = $request->checkout_key;
        if ($request->payment_method === 'xendit' && ! XenditCheckout::configured()) {
            $this->fail('Online payment is not configured yet. Choose cash on delivery or pay in store.');
        }
        $external = 'SHOP-' . $key;
        $publicToken = hash_hmac('sha256', 'shop-order:' . $key, config('app.key'));
        $payload = [
            'name' => trim($request->name), 'email' => strtolower(trim($request->email)),
            'phone' => trim($request->phone), 'address' => trim($request->address),
            'city' => trim($request->city), 'notes' => trim((string) $request->notes),
            'payment_method' => $request->payment_method,
            'allow_preorder' => $request->boolean('allow_preorder'),
            'items' => $request->items,
        ];
        $hash = hash('sha256', json_encode($payload));
        try {
            $orderId = DB::transaction(function () use ($request, $config, $external, $hash,
                $payload, $publicToken) {
                $existing = DB::table('customer_orders')->where('source_channel', 'web')
                    ->where('external_order_id', $external)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->payload_hash !== $hash) $this->fail('This checkout was already submitted with different details.');
                    return $existing->id;
                }
                $clientId = DB::table('clients')->insertGetId([
                    'code' => DocumentSequence::nextCode('clients', 'code', 1),
                    'name' => $payload['name'], 'email' => $payload['email'],
                    'phone' => $payload['phone'], 'adresse' => $payload['address'],
                    'city' => $payload['city'], 'country' => 'Philippines',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $orderId = DB::table('customer_orders')->insertGetId([
                    'Ref' => DocumentSequence::next('customer_orders', 'CO-', 6),
                    'client_id' => $clientId, 'warehouse_id' => $config->shop_warehouse_id,
                    'source_channel' => 'web', 'external_order_id' => $external,
                    'payload_hash' => $hash, 'public_token_hash' => hash('sha256', $publicToken),
                    'status' => 'pending_stock', 'checkout_payment_method' => $payload['payment_method'],
                    'notes' => $payload['notes'], 'created_by' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $seen = [];
                $total = 0.0;
                $allReserved = true;
                foreach ($request->items as $item) {
                    $productId = (int) $item['product_id'];
                    $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;
                    $product = DB::table('products as p')
                        ->join('units as u', 'u.id', '=', 'p.unit_sale_id')
                        ->where('p.id', $productId)->whereNull('p.deleted_at')
                        ->where('p.web_published', 1)->where('p.is_active', 1)
                        ->where('p.is_service', 0)->lockForUpdate()
                        ->first(['p.*', 'u.operator', 'u.operator_value']);
                    if (! $product || (float) $product->operator_value <= 0 || (float) $product->price < 0) {
                        $this->fail('A selected product is no longer available online.');
                    }
                    if ($product->is_variant) {
                        if (! $variantId || ! DB::table('product_variants')->where('id', $variantId)
                            ->where('product_id', $productId)->whereNull('deleted_at')->exists()) {
                            $this->fail('Choose an available variant for this product.');
                        }
                    } elseif ($variantId) {
                        $this->fail('This product does not have the chosen variant.');
                    }
                    $lineKey = $productId . ':' . ($variantId ?: 0);
                    if (isset($seen[$lineKey])) $this->fail('Combine duplicate items before checkout.');
                    $seen[$lineKey] = true;
                    $qty = (float) $item['quantity'];
                    $baseQty = $product->operator === '/'
                        ? $qty / (float) $product->operator_value
                        : $qty * (float) $product->operator_value;
                    $lineId = DB::table('customer_order_lines')->insertGetId([
                        'customer_order_id' => $orderId, 'product_id' => $productId,
                        'product_variant_id' => $variantId, 'quantity' => $baseQty,
                        'unit_price' => $product->price, 'status' => 'pending_stock',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $total += $qty * (float) $product->price;
                    try {
                        ReservationService::reserve('customer_order_line', $lineId,
                            $config->shop_warehouse_id, $productId, $variantId, $baseQty);
                        DB::table('customer_order_lines')->where('id', $lineId)->update([
                            'status' => 'reserved', 'updated_at' => now(),
                        ]);
                    } catch (ValidationException $e) {
                        if (! $request->boolean('allow_preorder')) throw $e;
                        $allReserved = false;
                    }
                }
                DB::table('customer_orders')->where('id', $orderId)->update([
                    'quoted_total' => round($total, 2),
                    'status' => $allReserved ? 'reserved' : 'pending_stock',
                    'updated_at' => now(),
                ]);
                if (! $allReserved && $payload['payment_method'] === 'xendit') {
                    $this->fail('Online payment needs all items in stock. Choose a manual payment method for a pre-order.');
                }
                CustomerOrderService::notifyStatus($orderId);
                return $orderId;
            }, 5);
        } catch (QueryException $e) {
            if (! in_array((int) ($e->errorInfo[1] ?? 0), [19, 1062], true)) throw $e;
            $existing = DB::table('customer_orders')->where('source_channel', 'web')
                ->where('external_order_id', $external)->first();
            if (! $existing) throw $e;
            if ($existing->payload_hash !== $hash) $this->fail('This checkout was already submitted with different details.');
            $orderId = $existing->id;
        }
        return response()->json(['id' => $orderId,
            'status' => DB::table('customer_orders')->where('id', $orderId)->value('status'),
            'url' => url('/shop/order/' . $publicToken)], 201)->header('Cache-Control', 'no-store');
    }

    public function order($token)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $order = DB::table('customer_orders')->where('public_token_hash', hash('sha256', $token))->first();
        abort_unless($order, 404);
        $lines = DB::table('customer_order_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_sale_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'l.product_variant_id')
            ->where('l.customer_order_id', $order->id)->orderBy('l.id')
            ->get(['l.quantity', 'l.unit_price', 'l.status', 'p.name', 'p.code',
                'v.name as variant', 'u.ShortName as unit', 'u.operator', 'u.operator_value']);
        foreach ($lines as $line) {
            $factor = $line->operator === '/' && (float) $line->operator_value > 0
                ? 1 / (float) $line->operator_value : max(1, (float) $line->operator_value);
            $line->display_quantity = (float) $line->quantity / $factor;
        }
        $payment = ShopPaymentService::latest($order->id);
        if ($payment && $payment->status === 'pending') {
            try { $payment = ShopPaymentService::refresh($payment); } catch (\Throwable $e) { /* show last known state */ }
        }
        return view('shop.order', ['order' => $order, 'lines' => $lines,
            'shopName' => optional($this->config())->shop_name ?: 'NASPIN TECH',
            'token' => $token, 'payment' => $payment]);
    }

    public function pay($token)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $order = DB::table('customer_orders')->where('public_token_hash', hash('sha256', $token))->first();
        abort_unless($order, 404);
        try {
            $payment = ShopPaymentService::start($order->id, url('/shop/order/' . $token));
            if ($payment->status === 'paid') return redirect('/shop/order/' . $token);
            return redirect()->away($payment->payment_link_url);
        } catch (\Throwable $e) {
            return redirect('/shop/order/' . $token)->with('payment_error', $e->getMessage());
        }
    }

    public function cancel(Request $request, $token)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token), 404);
        $order = DB::table('customer_orders')->where('public_token_hash', hash('sha256', $token))->first();
        abort_unless($order, 404);
        $payment = ShopPaymentService::latest($order->id);
        if ($payment && in_array($payment->status, ['pending', 'creating', 'review'], true)) {
            return redirect('/shop/order/' . $token)->with('payment_error',
                'This payment is still processing. Wait for its result before cancelling.');
        }
        CustomerOrderService::cancel($order->id, null, 'Cancelled by online customer');
        return redirect('/shop/order/' . $token);
    }
}
