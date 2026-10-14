<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Provider;
use App\Models\SupplierProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierPricingController extends Controller
{
    /**
     * Every supplier offer for every product, cheapest first per product, so
     * the comparison the product record could never show is the default view.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $rows = \DB::table('supplier_products as sp')
            ->join('providers as pr', 'pr.id', '=', 'sp.provider_id')
            ->join('products as p', 'p.id', '=', 'sp.product_id')
            ->whereNull('sp.deleted_at')
            ->whereNull('pr.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($request->filled('product_id'), function ($q) use ($request) {
                return $q->where('sp.product_id', $request->product_id);
            })
            ->when($request->filled('provider_id'), function ($q) use ($request) {
                return $q->where('sp.provider_id', $request->provider_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                return $q->where(function ($w) use ($request) {
                    $w->where('p.name', 'LIKE', "%{$request->search}%")
                        ->orWhere('p.code', 'LIKE', "%{$request->search}%")
                        ->orWhere('pr.name', 'LIKE', "%{$request->search}%");
                });
            })
            ->orderBy('p.name')->orderBy('sp.cost')
            ->select(
                'sp.id', 'sp.provider_id', 'sp.product_id', 'sp.supplier_sku',
                'sp.cost', 'sp.lead_time_days', 'sp.min_order_qty', 'sp.is_preferred',
                'pr.name as supplier', 'p.name as product', 'p.code', 'p.cost as product_cost'
            )->get();

        // Mark the cheapest offer per product so the screen can say so plainly.
        $best = [];
        foreach ($rows as $r) {
            if (! isset($best[$r->product_id]) || $r->cost < $best[$r->product_id]) {
                $best[$r->product_id] = $r->cost;
            }
        }

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'id' => $r->id,
                'provider_id' => $r->provider_id,
                'supplier' => $r->supplier,
                'product_id' => $r->product_id,
                'product' => $r->product,
                'code' => $r->code,
                'supplier_sku' => $r->supplier_sku,
                'cost' => (float) $r->cost,
                'product_cost' => (float) $r->product_cost,
                'lead_time_days' => (int) $r->lead_time_days,
                'min_order_qty' => (float) $r->min_order_qty,
                'is_preferred' => (bool) $r->is_preferred,
                'is_cheapest' => (float) $r->cost === (float) $best[$r->product_id],
            ];
        }

        return response()->json([
            'offers' => $data,
            'totalRows' => count($data),
            'suppliers' => Provider::where('deleted_at', '=', null)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Provider::class);

        $request->validate([
            'provider_id' => 'required|integer|exists:providers,id',
            'product_id' => 'required|integer|exists:products,id',
            'cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'min_order_qty' => 'nullable|numeric|min:0',
        ]);

        $exists = SupplierProduct::where('provider_id', $request->provider_id)
            ->where('product_id', $request->product_id)->first();

        if ($exists) {
            throw ValidationException::withMessages([
                'provider_id' => ['That supplier already has an offer for this product. Edit the existing one.'],
            ]);
        }

        \DB::transaction(function () use ($request) {
            if ($request['is_preferred']) {
                $this->clearPreferred($request->product_id);
            }

            SupplierProduct::create([
                'provider_id' => $request->provider_id,
                'product_id' => $request->product_id,
                'supplier_sku' => $request['supplier_sku'],
                'cost' => $request->cost,
                'lead_time_days' => $request['lead_time_days'] ?: 0,
                'min_order_qty' => $request['min_order_qty'] ?: 0,
                'is_preferred' => (bool) $request['is_preferred'],
            ]);

            $this->syncPreferredToProduct($request->product_id);
        });

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Provider::class);

        $request->validate([
            'cost' => 'required|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'min_order_qty' => 'nullable|numeric|min:0',
        ]);

        $offer = SupplierProduct::findOrFail($id);

        \DB::transaction(function () use ($request, $offer) {
            if ($request['is_preferred']) {
                $this->clearPreferred($offer->product_id);
            }

            $offer->update([
                'supplier_sku' => $request['supplier_sku'],
                'cost' => $request->cost,
                'lead_time_days' => $request['lead_time_days'] ?: 0,
                'min_order_qty' => $request['min_order_qty'] ?: 0,
                'is_preferred' => (bool) $request['is_preferred'],
            ]);

            $this->syncPreferredToProduct($offer->product_id);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Provider::class);

        $offer = SupplierProduct::findOrFail($id);
        $productId = $offer->product_id;

        \DB::transaction(function () use ($offer, $productId) {
            $offer->delete();
            $this->syncPreferredToProduct($productId);
        });

        return response()->json(['success' => true]);
    }

    /**
     * The offers for one product, cheapest first, with the lead time each
     * supplier quotes -- what a buyer needs before raising an order.
     */
    public function forProduct(Request $request, $productId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Provider::class);

        $offers = \DB::table('supplier_products as sp')
            ->join('providers as pr', 'pr.id', '=', 'sp.provider_id')
            ->whereNull('sp.deleted_at')->whereNull('pr.deleted_at')
            ->where('sp.product_id', $productId)
            ->orderBy('sp.cost')
            ->select('sp.*', 'pr.name as supplier')->get();

        return response()->json([
            'offers' => $offers,
            'product' => Product::select('id', 'name', 'code', 'cost')->findOrFail($productId),
        ]);
    }

    // ---------------------------------------------------------------

    private function clearPreferred($productId)
    {
        SupplierProduct::where('product_id', $productId)->update(['is_preferred' => false]);
    }

    /**
     * Keep products.preferred_provider_id in step, because the reorder report
     * built in Tier 1 reads that column.
     */
    private function syncPreferredToProduct($productId)
    {
        $preferred = SupplierProduct::where('product_id', $productId)
            ->where('is_preferred', true)->first();

        // Fall back to the cheapest offer when nobody is explicitly preferred.
        if (! $preferred) {
            $preferred = SupplierProduct::where('product_id', $productId)
                ->orderBy('cost')->first();
        }

        Product::where('id', $productId)->update([
            'preferred_provider_id' => $preferred ? $preferred->provider_id : null,
        ]);
    }
}
