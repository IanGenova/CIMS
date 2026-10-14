<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\TaxGroupRate;
use App\utils\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaxGroupController extends Controller
{
    //------------ GET ALL Tax Groups -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', TaxGroup::class);

        $perPage = \App\utils\ListLimit::of($request->limit, 10);
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = $request->SortType ?: 'desc';

        $groups = TaxGroup::where('deleted_at', '=', null)
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%");
                });
            });

        $totalRows = $groups->count();
        $groups = $groups->with('rates')->offset($offSet)->limit($perPage)
            ->orderBy($order, $dir)->get();

        $data = [];
        foreach ($groups as $g) {
            $data[] = [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'is_active' => (bool) $g->is_active,
                'component_count' => $g->rates->count(),
                // The single number a line actually gets charged, so the list
                // shows what the group does rather than just what it contains.
                'effective_percent' => $this->effectivePercent($g->rates),
                'rates' => $g->rates->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'name' => $r->name,
                        'rate' => (float) $r->rate,
                        'is_compound' => (bool) $r->is_compound,
                        'sort_order' => $r->sort_order,
                    ];
                }),
            ];
        }

        return response()->json([
            'taxgroups' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    //---------------- STORE -------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', TaxGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'rates' => 'required|array|min:1',
            'rates.*.name' => 'required|string|max:192',
            'rates.*.rate' => 'required|numeric|min:0|max:100',   // a percentage (K50)
        ]);

        \DB::transaction(function () use ($request) {
            $group = TaxGroup::create([
                'name' => $request['name'],
                'description' => $request['description'],
                'is_active' => $request['is_active'] === null ? true : (bool) $request['is_active'],
            ]);

            $this->saveRates($group->id, $request['rates']);
        });

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function show($id)
    {
        //
    }

    //---------------- UPDATE -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', TaxGroup::class);

        $request->validate([
            'name' => 'required|string|max:192',
            'rates' => 'required|array|min:1',
            'rates.*.name' => 'required|string|max:192',
            'rates.*.rate' => 'required|numeric|min:0|max:100',   // a percentage (K50)
        ]);

        \DB::transaction(function () use ($request, $id) {
            TaxGroup::whereId($id)->update([
                'name' => $request['name'],
                'description' => $request['description'],
                'is_active' => (bool) $request['is_active'],
            ]);

            // Components are replaced wholesale; they carry no history of their own.
            TaxGroupRate::where('tax_group_id', $id)->delete();
            $this->saveRates($id, $request['rates']);
        });

        PricingService::forget();

        return response()->json(['success' => true]);
    }

    //---------------- DELETE -------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', TaxGroup::class);

        $inUse = Product::where('tax_group_id', $id)->where('deleted_at', '=', null)->count();
        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'tax_group' => ['This tax group is still assigned to ' . $inUse . ' product(s). Move them to another group first.'],
            ]);
        }

        TaxGroup::whereId($id)->update(['deleted_at' => now()]);
        PricingService::forget();

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', TaxGroup::class);

        $ids = $request['selectedIds'];
        $blocked = Product::whereIn('tax_group_id', $ids)->where('deleted_at', '=', null)->count();
        if ($blocked > 0) {
            throw ValidationException::withMessages([
                'tax_group' => ['One of the selected groups is still assigned to products.'],
            ]);
        }

        TaxGroup::whereIn('id', $ids)->update(['deleted_at' => now()]);
        PricingService::forget();

        return response()->json(['success' => true]);
    }

    /** Groups usable in a product form. */
    public function options(Request $request)
    {
        $groups = TaxGroup::where('deleted_at', '=', null)->where('is_active', true)
            ->with('rates')->orderBy('name')->get();

        $data = [];
        foreach ($groups as $g) {
            $data[] = [
                'id' => $g->id,
                'name' => $g->name,
                'effective_percent' => $this->effectivePercent($g->rates),
            ];
        }

        return response()->json(['taxgroups' => $data]);
    }

    // ---------------------------------------------------------------

    private function saveRates($groupId, array $rates)
    {
        $i = 0;
        foreach ($rates as $r) {
            TaxGroupRate::create([
                'tax_group_id' => $groupId,
                'name' => $r['name'],
                'rate' => (float) $r['rate'],
                'is_compound' => ! empty($r['is_compound']),
                'sort_order' => isset($r['sort_order']) ? (int) $r['sort_order'] : $i,
            ]);
            $i++;
        }
    }

    /**
     * Fold the components into the one percentage a line is charged.
     * Mirrors PricingService::taxFor so the screen and the sale agree.
     */
    private function effectivePercent($rates)
    {
        $base = 100.0;
        $running = $base;
        $charged = 0.0;

        foreach ($rates as $r) {
            $on = $r->is_compound ? $running : $base;
            $amount = $on * ((float) $r->rate) / 100;
            $charged += $amount;
            $running += $amount;
        }

        return round($charged, 4);
    }
}
