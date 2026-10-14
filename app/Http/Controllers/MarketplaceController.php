<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Warehouse;
use App\utils\MarketplaceAuth;
use App\utils\MarketplaceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The screen behind marketplace selling.
 *
 * Most of this is ordinary listing and editing. The part that earns the screen
 * is the queue: orders held because nobody has mapped a SKU are invisible
 * otherwise, and an order nobody can see is an order nobody ships.
 */
class MarketplaceController extends Controller
{
    /** Editing stock mappings is the same decision as editing stock. */
    private function may(Request $request, $ability = 'update')
    {
        $this->authorizeForUser($request->user('api'), $ability, Product::class);
    }

    /**
     * Everything the screen opens with, in one request rather than four.
     */
    public function index(Request $request)
    {
        $this->may($request, 'view');

        $accounts = DB::table('marketplace_accounts as a')
            ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
            ->whereNull('a.deleted_at')
            ->select('a.*', 'w.name as warehouse_name')
            ->orderBy('a.name')
            ->get();

        $withCounts = [];
        foreach ($accounts as $account) {
            $withCounts[] = [
                'id' => (int) $account->id,
                'channel' => $account->channel,
                'name' => $account->name,
                'external_shop_id' => $account->external_shop_id,
                'region' => $account->region,
                'warehouse_id' => $account->warehouse_id === null ? null : (int) $account->warehouse_id,
                'warehouse_name' => $account->warehouse_name,
                'client_id' => $account->client_id === null ? null : (int) $account->client_id,
                'is_active' => (bool) $account->is_active,
                // Whether, never what: tokens stay on the server.
                'authorised' => ! empty($account->access_token),
                'token_expires_at' => $account->token_expires_at,
                'connected_at' => $account->connected_at ?? null,
                'auth_error' => $account->auth_error ?? null,
                'orders_synced_at' => $account->orders_synced_at,
                'listings' => DB::table('marketplace_listings')
                    ->where('marketplace_account_id', $account->id)
                    ->whereNull('deleted_at')->count(),
                'waiting' => DB::table('marketplace_orders')
                    ->where('marketplace_account_id', $account->id)
                    ->whereIn('state', ['unmapped', 'failed'])
                    ->whereNull('deleted_at')->count(),
            ];
        }

        return response()->json([
            'accounts' => $withCounts,
            'waiting' => MarketplaceSyncService::needingAttention(),
            'warehouses' => Warehouse::where('deleted_at', '=', null)->select('id', 'name')->get(),
            'clients' => DB::table('clients')->whereNull('deleted_at')
                ->select('id', 'name')->orderBy('name')->limit(500)->get(),
            'channels' => [
                ['value' => 'shopee', 'label' => 'Shopee'],
                ['value' => 'lazada', 'label' => 'Lazada'],
            ],
        ]);
    }

    // ------------------------------------------------------------- connecting

    /**
     * The marketplace page where the seller approves this app. The screen
     * sends the browser there; the marketplace sends it back to callback().
     */
    public function connect(Request $request, $id)
    {
        $this->may($request);

        $account = DB::table('marketplace_accounts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $account) {
            return response()->json(['message' => 'That shop is not on this system.'], 404);
        }

        $result = MarketplaceAuth::connectUrl($account, $request->user('api')->id, $request->getSchemeAndHttpHost());
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json(['url' => $result['url']]);
    }

    /**
     * Back from Shopee or Lazada. A browser visit in the signed-in session,
     * checked against the single-use state before any code is exchanged, and
     * always answered with a redirect to the marketplace screen -- never to an
     * address the request names.
     */
    public function callback(Request $request, $channel)
    {
        $this->authorizeForUser($request->user(), 'update', Product::class);

        $result = MarketplaceAuth::complete($channel, $request->query(), $request->user()->id);

        if (isset($result['error'])) {
            return redirect('/app/products/marketplace?' . http_build_query(array_filter([
                'connect_error' => $result['error'],
                'account' => $result['account_id'],
            ])));
        }

        return redirect('/app/products/marketplace?connected=' . $result['account_id']);
    }

    // ------------------------------------------------------------- accounts

    public function storeAccount(Request $request)
    {
        $this->may($request);

        $request->validate([
            'channel' => 'required|string|in:shopee,lazada',
            'name' => 'required|string|max:128',
            'external_shop_id' => 'nullable|string|max:64',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'region' => 'nullable|string|max:8',
        ]);

        $id = DB::table('marketplace_accounts')->insertGetId([
            'channel' => $request->channel,
            'name' => $request->name,
            'external_shop_id' => $request->external_shop_id,
            'region' => $request->region ?: 'PH',
            'warehouse_id' => $request->warehouse_id,
            'client_id' => $request->client_id,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id]);
    }

    public function updateAccount(Request $request, $id)
    {
        $this->may($request);

        $request->validate([
            'name' => 'required|string|max:128',
            'external_shop_id' => 'nullable|string|max:64',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'is_active' => 'required|boolean',
        ]);

        DB::table('marketplace_accounts')->where('id', $id)->update([
            'name' => $request->name,
            'external_shop_id' => $request->external_shop_id,
            'warehouse_id' => $request->warehouse_id,
            'client_id' => $request->client_id,
            'is_active' => (bool) $request->is_active,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------- mappings

    public function listings(Request $request)
    {
        $this->may($request, 'view');

        $q = DB::table('marketplace_listings as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('marketplace_accounts as a', 'a.id', '=', 'l.marketplace_account_id')
            ->whereNull('l.deleted_at');

        if ($request->filled('account_id')) {
            $q->where('l.marketplace_account_id', $request->account_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('l.external_sku', 'LIKE', "%{$s}%")
                    ->orWhere('p.name', 'LIKE', "%{$s}%")
                    ->orWhere('p.code', 'LIKE', "%{$s}%");
            });
        }

        $rows = $q->select('l.*', 'p.name as product_name', 'p.code as product_code',
                'a.name as account_name', 'a.channel')
            ->orderBy('l.external_sku')
            ->limit(500)
            ->get();

        return response()->json(['listings' => $rows, 'totalRows' => $rows->count()]);
    }

    public function storeListing(Request $request)
    {
        $this->may($request);

        $request->validate([
            'marketplace_account_id' => 'required|integer|exists:marketplace_accounts,id',
            'product_id' => 'required|integer|exists:products,id',
            'external_sku' => 'required|string|max:128',
            'external_item_id' => 'nullable|string|max:64',
            'push_stock' => 'nullable|boolean',
        ]);

        // One SKU maps to one product. Two mappings for the same SKU would make
        // which product an order sells depend on row order.
        $exists = DB::table('marketplace_listings')
            ->where('marketplace_account_id', $request->marketplace_account_id)
            ->where('external_sku', $request->external_sku)
            ->whereNull('deleted_at')->exists();

        if ($exists) {
            return response()->json([
                'message' => 'That SKU is already mapped for this shop.',
            ], 422);
        }

        $id = DB::table('marketplace_listings')->insertGetId([
            'marketplace_account_id' => $request->marketplace_account_id,
            'product_id' => $request->product_id,
            'product_variant_id' => $request->product_variant_id,
            'external_sku' => $request->external_sku,
            'external_item_id' => $request->external_item_id,
            'push_stock' => $request->input('push_stock', true) ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id]);
    }

    public function updateListing(Request $request, $id)
    {
        $this->may($request);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'external_item_id' => 'nullable|string|max:64',
            'push_stock' => 'required|boolean',
        ]);

        DB::table('marketplace_listings')->where('id', $id)->update([
            'product_id' => $request->product_id,
            'external_item_id' => $request->external_item_id,
            'push_stock' => (bool) $request->push_stock,
            // What was last pushed no longer describes this listing, so the next
            // run sends the quantity rather than skipping it as unchanged.
            'last_pushed_qty' => null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function destroyListing(Request $request, $id)
    {
        $this->may($request, 'delete');

        DB::table('marketplace_listings')->where('id', $id)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    // --------------------------------------------------------------- orders

    public function orders(Request $request)
    {
        $this->may($request, 'view');

        $q = DB::table('marketplace_orders as o')
            ->leftJoin('marketplace_accounts as a', 'a.id', '=', 'o.marketplace_account_id')
            ->leftJoin('sales as s', 's.id', '=', 'o.sale_id')
            ->whereNull('o.deleted_at');

        if ($request->filled('account_id')) {
            $q->where('o.marketplace_account_id', $request->account_id);
        }
        if ($request->filled('state')) {
            $q->where('o.state', $request->state);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('o.external_order_id', 'LIKE', "%{$s}%")
                    ->orWhere('o.buyer_name', 'LIKE', "%{$s}%");
            });
        }

        $rows = $q->select('o.id', 'o.external_order_id', 'o.external_status', 'o.ordered_at',
                'o.total', 'o.currency', 'o.buyer_name', 'o.state', 'o.state_reason',
                'o.sale_id', 'a.name as account_name', 'a.channel', 's.Ref as sale_ref')
            ->orderByDesc('o.ordered_at')
            ->limit(300)
            ->get();

        return response()->json(['orders' => $rows, 'totalRows' => $rows->count()]);
    }

    /**
     * Put a held order through, now that its SKU is mapped.
     */
    public function retryOrder(Request $request, $id)
    {
        $this->may($request);

        $result = MarketplaceSyncService::retryOrder($id);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result);
    }

    /**
     * Run a sync now rather than waiting for the schedule.
     */
    public function syncNow(Request $request, $id)
    {
        $this->may($request);

        $orders = MarketplaceSyncService::importOrders($id);
        $stock = MarketplaceSyncService::pushStock($id);

        return response()->json(['orders' => $orders, 'stock' => $stock]);
    }

    /**
     * Products to map a SKU onto. Searched rather than listed, because a
     * catalogue of any size is not a dropdown.
     */
    public function products(Request $request)
    {
        $this->may($request, 'view');

        $q = DB::table('products')->whereNull('deleted_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($w) use ($s) {
                $w->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%");
            });
        }

        return response()->json([
            'products' => $q->select('id', 'name', 'code')->orderBy('name')->limit(50)->get(),
        ]);
    }
}
