<?php

namespace App\Http\Controllers;

use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\Unit;
use App\utils\BatchService;
use App\utils\StockLedger;
use App\utils\TillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Baskets put to one side.
 *
 * A hold is not a sale: no invoice number is taken and no money changes hands.
 * It is stored as the cart itself rather than as a half-made sale, which would
 * otherwise have to be excluded by hand from every report that counts sales.
 *
 * But its goods are set aside. While a basket is on hold its stock is off the
 * shelf count, so another till cannot sell the same last bags (asked for by the
 * owner). The stock comes back when the basket is discarded; when it is
 * recalled, where the sale then takes it for good; or by itself once a hold
 * is forgotten for RELEASE_AFTER_HOURS -- the basket stays recallable, and
 * that sale re-checks stock like any other. Every movement is in the stock
 * history as "pos_hold" / "pos_hold_release". Tests: PosHoldControllerStockTest.
 *
 * Prices are still re-checked on recall: the sale is made then, not now.
 */
class PosHoldController extends Controller
{
    /** A forgotten basket gives its stock back after this long. */
    const RELEASE_AFTER_HOURS = 12;

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        self::releaseForgotten();

        $q = DB::table('pos_holds as h')
            ->leftJoin('clients as c', 'c.id', '=', 'h.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->whereNull('h.deleted_at');

        if ($request->filled('warehouse_id')) {
            $q->where('h.warehouse_id', $request->warehouse_id);
        }
        // Only the user's own warehouses (DATATEST K14).
        $allowed = \App\utils\WarehouseAccess::allowedIds();
        if ($allowed !== null) {
            $q->whereIn('h.warehouse_id', $allowed);
        }

        $rows = $q->select('h.id', 'h.label', 'h.notes', 'h.item_count', 'h.total',
                'h.created_at', 'h.warehouse_id', 'h.stock_held', 'h.stock_released_at',
                'c.name as client', 'u.username as cashier')
            ->orderByDesc('h.id')
            ->limit(100)
            ->get();

        return response()->json(['holds' => $rows, 'totalRows' => $rows->count()]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'label' => 'required|string|max:64',
            'cart' => 'required|array|min:1',
            'client_id' => 'nullable|integer|exists:clients,id',
            'notes' => 'nullable|string|max:255',
            'total' => 'nullable|numeric|min:0',
            'cart.*.product_id' => 'required|integer|exists:products,id',
            'cart.*.sale_unit_id' => 'required|integer|exists:units,id',
            'cart.*.quantity' => 'required|numeric|gt:0',
        ]);

        $session = TillService::currentFor(Auth::user()->id, $request->warehouse_id);

        // The basket and its stock together, or neither.
        $id = DB::transaction(function () use ($request, $session) {
            $id = DB::table('pos_holds')->insertGetId([
                'user_id' => Auth::user()->id,
                'warehouse_id' => $request->warehouse_id,
                'till_session_id' => $session ? $session->id : null,
                'client_id' => $request->client_id,
                'label' => $request->label,
                'notes' => $request->notes,
                'cart' => json_encode($request->cart),
                'item_count' => count($request->cart),
                'total' => round((float) $request->input('total', 0), 4),
                'stock_held' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reserved = self::setAside($id, (int) $request->warehouse_id, $request->cart);
            DB::table('pos_holds')->where('id', $id)->update(['reserved' => json_encode($reserved)]);

            return $id;
        });

        return response()->json(['hold' => DB::table('pos_holds')->where('id', $id)->first()]);
    }

    // ------------------------------------------------------------- stock

    /** A line's quantity in the product's base unit, as a sale counts it. */
    private static function baseQuantity(array $line)
    {
        $unit = Unit::find($line['sale_unit_id']);
        $qty = (float) $line['quantity'];
        if (! $unit) {
            return $qty;
        }

        return $unit->operator == '/' ? $qty / $unit->operator_value : $qty * $unit->operator_value;
    }

    /** Take the basket's goods off the shelf count, the way a sale would. */
    private static function setAside($holdId, $warehouseId, array $cart)
    {
        $reserved = [];
        StockLedger::context('pos_hold', 'PosHold', $holdId);
        try {
            foreach ($cart as $line) {
                $productId = (int) $line['product_id'];
                $variantId = $line['product_variant_id'] ?? null;
                $base = self::baseQuantity($line);
                $name = $line['name'] ?? DB::table('products')->where('id', $productId)->value('name');

                if (BatchService::isTracked($productId)) {
                    // Oldest expiry first, expired lots refused -- as a sale.
                    BatchService::allocate($productId, $variantId, $warehouseId, $base, 'pos_hold', 'PosHold', $holdId);
                    $reserved[] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $base, 'batch' => true];
                    continue;
                }

                $stock = product_warehouse::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->when($variantId !== null, function ($q) use ($variantId) {
                        $q->where('product_variant_id', $variantId);
                    })
                    ->lockForUpdate()->first();

                if (! $stock || (float) $stock->qte + 0.000001 < $base) {
                    throw ValidationException::withMessages(['stock' => [sprintf(
                        'Not enough %s on the shelf to hold this basket: it needs %s, %s is available.',
                        $name, rtrim(rtrim(number_format($base, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($stock ? (float) $stock->qte : 0, 2, '.', ''), '0'), '.')
                    )]]);
                }

                $stock->qte = (float) $stock->qte - $base;
                $stock->save();
                $reserved[] = ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $base, 'batch' => false];
            }
        } finally {
            StockLedger::clear();
        }

        return $reserved;
    }

    /**
     * Put a held basket's goods back. Once only: a hold that never set stock
     * aside (held before this change), or already gave it back, returns nothing.
     * Call inside a transaction holding the row lock.
     */
    private static function giveBack($hold)
    {
        if (! $hold->stock_held || $hold->stock_released_at) {
            return;
        }

        $lines = json_decode((string) $hold->reserved, true) ?: [];
        StockLedger::context('pos_hold_release', 'PosHold', $hold->id);
        try {
            // Batch-tracked goods go back into the very lots they came from.
            if (collect($lines)->contains('batch', true)) {
                BatchService::reverse('PosHold', $hold->id);
            }
            foreach ($lines as $line) {
                if ($line['batch']) {
                    continue;
                }
                $stock = product_warehouse::where('warehouse_id', $hold->warehouse_id)
                    ->where('product_id', $line['product_id'])
                    ->when($line['variant_id'] !== null, function ($q) use ($line) {
                        $q->where('product_variant_id', $line['variant_id']);
                    })
                    ->lockForUpdate()->first();
                if ($stock) {
                    $stock->qte = (float) $stock->qte + (float) $line['qty'];
                    $stock->save();
                }
            }
        } finally {
            StockLedger::clear();
        }

        DB::table('pos_holds')->where('id', $hold->id)
            ->update(['stock_held' => false, 'stock_released_at' => now(), 'updated_at' => now()]);
    }

    /** Baskets held longer than RELEASE_AFTER_HOURS give their stock back. */
    /**
     * A sale by the person who recalled baskets here takes their goods: put
     * them back first, inside the sale's transaction, so no other till can
     * take them in between (DATATEST K77).
     */
    public static function releaseRecalledBy($userId, $warehouseId)
    {
        $holds = DB::table('pos_holds')->where('recalled_by', $userId)->where('warehouse_id', $warehouseId)
            ->where('stock_held', true)->whereNull('stock_released_at')
            ->lockForUpdate()->get();
        foreach ($holds as $hold) {
            self::giveBack($hold);
        }

        return $holds->count();
    }

    public static function releaseForgotten()
    {
        // Forgotten on the shelf, or recalled and never rung up.
        $stale = DB::table('pos_holds')->where('stock_held', true)
            ->whereNull('stock_released_at')
            ->where(function ($q) {
                $q->where(function ($held) {
                    $held->whereNull('deleted_at')->where('created_at', '<', now()->subHours(self::RELEASE_AFTER_HOURS));
                })->orWhere(function ($recalled) {
                    $recalled->whereNotNull('recalled_at')->where('recalled_at', '<', now()->subHours(self::RELEASE_AFTER_HOURS));
                });
            })
            ->pluck('id');

        foreach ($stale as $id) {
            DB::transaction(function () use ($id) {
                $hold = DB::table('pos_holds')->where('id', $id)->lockForUpdate()->first();
                self::giveBack($hold);
            });
        }

        return count($stale);
    }

    /** Hand a basket back to the till. */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $hold = DB::table('pos_holds')->whereNull('deleted_at')->where('id', $id)->first();
        if (! $hold) {
            return response()->json(['message' => 'That held basket is no longer there.'], 404);
        }
        \App\utils\WarehouseAccess::assertAllows($hold->warehouse_id);

        $hold->cart = json_decode($hold->cart, true);

        return response()->json(['hold' => $hold]);
    }

    /**
     * Recall it: hand back the basket and take the hold away in one step, so two
     * tills cannot pick up the same basket and ring it twice.
     */
    public function recall(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $hold = DB::transaction(function () use ($id) {
            $row = DB::table('pos_holds')->whereNull('deleted_at')
                ->where('id', $id)->lockForUpdate()->first();

            if (! $row) {
                return null;
            }
            \App\utils\WarehouseAccess::assertAllows($row->warehouse_id);

            // The goods stay set aside for whoever recalled the basket: giving
            // them back now let another till sell them while this one was
            // still ringing it up (DATATEST K77). That person's next sale
            // releases them in its own transaction (releaseRecalledBy).
            DB::table('pos_holds')->where('id', $id)->update([
                'deleted_at' => now(), 'updated_at' => now(),
                'recalled_by' => Auth::id(), 'recalled_at' => now(),
            ]);

            return $row;
        });

        if (! $hold) {
            return response()->json([
                'message' => 'That held basket has already been recalled or discarded.',
            ], 404);
        }

        $hold->cart = json_decode($hold->cart, true);

        return response()->json(['hold' => $hold]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        // Discarded: its goods go back on the shelf.
        $gone = DB::transaction(function () use ($id) {
            $hold = DB::table('pos_holds')->whereNull('deleted_at')->where('id', $id)->lockForUpdate()->first();
            if (! $hold) {
                return false;
            }
            \App\utils\WarehouseAccess::assertAllows($hold->warehouse_id);
            self::giveBack($hold);
            DB::table('pos_holds')->where('id', $id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return true;
        });

        if (! $gone) {
            return response()->json(['message' => 'That held basket is no longer there.'], 404);
        }

        return response()->json(['success' => true]);
    }
}
