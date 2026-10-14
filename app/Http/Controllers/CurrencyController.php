<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\utils\CurrencyService;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{

    //------------ GET ALL Currency -----------\\

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Currency::class);
        // How many items do you want to display.
        $perPage = \App\utils\ListLimit::of($request->limit);
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers();

        $currencies = Currency::where('deleted_at', '=', null)

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('name', 'LIKE', "%{$request->search}%")
                        ->orWhere('code', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $currencies->count();
        $currencies = $currencies->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        return response()->json([
            'currencies' => $currencies,
            'totalRows' => $totalRows,
        ]);
    }

    //---------------- STORE NEW Currency -------------\\

    /**
     * A rate is stored to 8 decimals: anything smaller is saved as 0 and
     * prices everything at nothing (DATATEST K58).
     */
    private static function rules()
    {
        return [
            'code' => 'required|string|max:192',
            'name' => 'required|string|max:192',
            'symbol' => 'required|string|max:192',
            'exchange_rate' => 'nullable|numeric|gte:0.00000001|max:999999999999',
        ];
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Currency::class);

        request()->validate(self::rules());
        \App\utils\Uniqueness::assertFree('currencies', 'code', $request['code'], null, 'code', 'currency code');

        $isBase = (bool) $request['is_base'];

        \DB::transaction(function () use ($request, $isBase) {
            // Exactly one currency is the base, and its rate is 1 by definition.
            if ($isBase) {
                Currency::where('is_base', true)->update(['is_base' => false]);
            }

            Currency::create([
                'name' => $request['name'],
                'code' => $request['code'],
                'symbol' => $request['symbol'],
                'exchange_rate' => $isBase ? 1 : ($request['exchange_rate'] ?: 1),
                'is_base' => $isBase,
                'rate_updated_at' => Carbon::now(),
            ]);
        });

        CurrencyService::forget();

        return response()->json(['success' => true]);

    }

    //------------ function show -----------\\

    public function show($id){
        //
        
        }

    //---------------- UPDATE Currency -------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Currency::class);

        request()->validate(self::rules());
        \App\utils\Uniqueness::assertFree('currencies', 'code', $request['code'], $id, 'code', 'currency code');

        $isBase = (bool) $request['is_base'];

        \DB::transaction(function () use ($request, $id, $isBase) {
            if ($isBase) {
                Currency::where('is_base', true)->where('id', '!=', $id)
                    ->update(['is_base' => false]);
            }

            $current = Currency::findOrFail($id);
            $newRate = $isBase ? 1 : ($request['exchange_rate'] ?: 1);

            $data = [
                'name' => $request['name'],
                'code' => $request['code'],
                'symbol' => $request['symbol'],
                'exchange_rate' => $newRate,
                'is_base' => $isBase,
            ];

            // Only stamp the time when the rate actually moved, so the column
            // means "when this rate was last set" rather than "last edited".
            if ((float) $current->exchange_rate !== (float) $newRate) {
                $data['rate_updated_at'] = Carbon::now();
            }

            Currency::whereId($id)->update($data);
            // A rate change restates every price in this currency (DATATEST K60).
            \App\Observers\AuditObserver::record(Currency::class, $id, 'updated',
                ['exchange_rate' => $current->exchange_rate, 'code' => $current->code],
                array_intersect_key($data, array_flip(['exchange_rate', 'code', 'name', 'symbol', 'is_base'])));
        });

        CurrencyService::forget();

        return response()->json(['success' => true]);

    }

    //------------ Delete Currency -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Currency::class);
        if (\App\utils\DeleteGuard::missing('currencies', $id)) {
            return \App\utils\DeleteGuard::notFound();
        }

        if ($reason = \App\utils\DeleteGuard::currency($id)) {
            return response()->json(['message' => $reason], 422);
        }

        Currency::whereId($id)->update([
            'deleted_at' => Carbon::now(),
        ]);
        \App\Observers\AuditObserver::record(Currency::class, $id, 'deleted');

        return response()->json(['success' => true]);
    }

    //-------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Currency::class);
        $selectedIds = $request->selectedIds;

        if ($reason = \App\utils\DeleteGuard::firstReason('currency', (array) $selectedIds)) {
            return response()->json(['message' => $reason], 422);
        }

        foreach ($selectedIds as $Currency_id) {
            Currency::whereId($Currency_id)->update([
                'deleted_at' => Carbon::now(),
            ]);
        }
        return response()->json(['success' => true]);
    }

    //------------ GET ALL Currency WITHOUT PAGINATE -----------\\

    public function Get_Currencies()
    {
        $Currencies = Currency::where('deleted_at', null)->get(['id', 'name']);
        return response()->json($Currencies);
    }

}
