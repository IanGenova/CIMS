<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\utils\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerGroupController extends Controller
{
    //------------ GET ALL Customer Groups -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CustomerGroup::class);

        $perPage = \App\utils\ListLimit::of($request->limit, 10);
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $groups = CustomerGroup::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $groups->count();
        $groups = $groups->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        $data = [];
        foreach ($groups as $g) {
            $data[] = [
                'id' => $g->id,
                'name' => $g->name,
                'default_discount_percent' => (float) $g->default_discount_percent,
                'is_active' => (bool) $g->is_active,
                'customer_count' => Client::where('customer_group_id', $g->id)
                    ->where('deleted_at', '=', null)->count(),
                'price_list_count' => PriceList::where('customer_group_id', $g->id)
                    ->where('deleted_at', '=', null)->count(),
            ];
        }

        return response()->json([
            'customergroups' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', CustomerGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        CustomerGroup::create([
            'name' => $request['name'],
            'default_discount_percent' => $request['default_discount_percent'] ?: 0,
            'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
        ]);

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CustomerGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        CustomerGroup::whereId($id)->update([
            'name' => $request['name'],
            'default_discount_percent' => $request['default_discount_percent'] ?: 0,
            'is_active' => (bool) $request['is_active'],
        ]);

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CustomerGroup::class);

        $inUse = Client::where('customer_group_id', $id)->where('deleted_at', '=', null)->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'customer_group' => ['This group still has ' . $inUse . ' customer(s) in it. Move them out first.'],
            ]);
        }

        CustomerGroup::whereId($id)->update(['deleted_at' => now()]);
        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CustomerGroup::class);

        $ids = $request['selectedIds'];
        $blocked = Client::whereIn('customer_group_id', $ids)->where('deleted_at', '=', null)->count();
        if ($blocked > 0) {
            throw ValidationException::withMessages([
                'customer_group' => ['One of the selected groups still has customers in it.'],
            ]);
        }

        CustomerGroup::whereIn('id', $ids)->update(['deleted_at' => now()]);
        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function options(Request $request)
    {
        return response()->json([
            'customergroups' => CustomerGroup::where('deleted_at', '=', null)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'default_discount_percent']),
        ]);
    }

    // ==================== price lists ====================

    public function lists(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CustomerGroup::class);

        $lists = PriceList::where('deleted_at', '=', null)
            ->with('group')->orderByDesc('id')->get();

        $data = [];
        foreach ($lists as $l) {
            $data[] = [
                'id' => $l->id,
                'name' => $l->name,
                'customer_group_id' => $l->customer_group_id,
                'customer_group' => $l->group ? $l->group->name : null,
                'starts_on' => $l->starts_on ? $l->starts_on->format('Y-m-d') : null,
                'ends_on' => $l->ends_on ? $l->ends_on->format('Y-m-d') : null,
                'is_active' => (bool) $l->is_active,
                'is_live' => $l->isLiveOn(date('Y-m-d')),
                'item_count' => PriceListItem::where('price_list_id', $l->id)->count(),
            ];
        }

        return response()->json([
            'pricelists' => $data,
            'customergroups' => CustomerGroup::where('deleted_at', '=', null)
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeList(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', CustomerGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'customer_group_id' => 'required|integer|exists:customer_groups,id',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);

        PriceList::create([
            'name' => $request['name'],
            'customer_group_id' => $request['customer_group_id'],
            'starts_on' => $request['starts_on'] ?: null,
            'ends_on' => $request['ends_on'] ?: null,
            'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
        ]);

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function updateList(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CustomerGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'customer_group_id' => 'required|integer|exists:customer_groups,id',
            'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
        ]);

        PriceList::whereId($id)->update([
            'name' => $request['name'],
            'customer_group_id' => $request['customer_group_id'],
            'starts_on' => $request['starts_on'] ?: null,
            'ends_on' => $request['ends_on'] ?: null,
            'is_active' => (bool) $request['is_active'],
        ]);

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function destroyList(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CustomerGroup::class);

        \DB::transaction(function () use ($id) {
            PriceListItem::where('price_list_id', $id)->delete();
            PriceList::whereId($id)->update(['deleted_at' => now()]);
        });

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    /** The rows of one list, plus what the products normally cost. */
    public function listItems(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', CustomerGroup::class);

        $items = \DB::table('price_list_items as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.price_list_id', $id)
            ->orderBy('p.name')->orderBy('i.min_quantity')
            ->select(
                'i.id', 'i.product_id', 'i.min_quantity', 'i.price', 'i.discount_percent',
                'p.name as product', 'p.code', 'p.price as list_price'
            )->get();

        $rows = [];
        foreach ($items as $i) {
            // Show the resulting price whichever way the row was expressed.
            $effective = $i->price !== null
                ? (float) $i->price
                : ($i->discount_percent !== null
                    ? round((float) $i->list_price - ((float) $i->list_price * (float) $i->discount_percent / 100), 4)
                    : (float) $i->list_price);

            $rows[] = [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product' => $i->product,
                'code' => $i->code,
                'list_price' => (float) $i->list_price,
                'min_quantity' => (float) $i->min_quantity,
                'price' => $i->price === null ? null : (float) $i->price,
                'discount_percent' => $i->discount_percent === null ? null : (float) $i->discount_percent,
                'effective_price' => $effective,
            ];
        }

        $list = PriceList::findOrFail($id);

        return response()->json([
            'items' => $rows,
            'list' => ['id' => $list->id, 'name' => $list->name],
        ]);
    }

    public function storeListItem(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CustomerGroup::class);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'min_quantity' => 'nullable|numeric|min:0',
            'price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        // A row that sets neither is a row that does nothing.
        if ($request['price'] === null && $request['discount_percent'] === null) {
            throw ValidationException::withMessages([
                'price' => ['Give either a fixed price or a discount percentage.'],
            ]);
        }

        $minQty = (float) ($request['min_quantity'] ?: 0);

        // One row per product per band, or the resolver's "highest band wins"
        // rule would depend on insertion order.
        $exists = PriceListItem::where('price_list_id', $id)
            ->where('product_id', $request['product_id'])
            ->where('min_quantity', $minQty)->first();

        if ($exists) {
            throw ValidationException::withMessages([
                'product_id' => ['That product already has a row for quantities from ' . $minQty . '.'],
            ]);
        }

        PriceListItem::create([
            'price_list_id' => $id,
            'product_id' => $request['product_id'],
            'min_quantity' => $minQty,
            'price' => $request['price'],
            'discount_percent' => $request['price'] !== null ? null : $request['discount_percent'],
        ]);

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function destroyListItem(Request $request, $itemId)
    {
        $this->authorizeForUser($request->user('api'), 'update', CustomerGroup::class);

        PriceListItem::whereId($itemId)->delete();
        PricingService::forget();

        return response()->json(['success' => true]);
    }

    /**
     * What a given customer would actually pay, so the sale form can show the
     * resolved price and where it came from instead of guessing.
     */
    public function resolve(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'quantity' => 'nullable|numeric|min:0',
        ]);

        PricingService::forget();
        $price = PricingService::priceFor(
            $request->product_id, $request->client_id, $request->quantity ?: 1
        );
        $tax = PricingService::taxFor($request->product_id, $request->client_id);

        return response()->json([
            'price' => $price['price'],
            'price_source' => $price['source'],
            'list_price' => $price['list_price'],
            'tax_percent' => $tax['percent'],
            'tax_source' => $tax['source'],
            'tax_components' => $tax['components'],
        ]);
    }
}
