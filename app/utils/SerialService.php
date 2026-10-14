<?php

namespace App\utils;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serial numbers (or IMEIs) for products sold one traceable unit at a time:
 * appliances, electronics, phones, power tools.
 *
 * The stock count stays where it always was (product_warehouse); this keeps
 * the units behind it. Every path that moves a serial-tracked product names
 * the units it moves, and the count must match the quantity in base units:
 *
 *   receive()        goods receipt, adjustment (add)       -> in_stock
 *   sell()           POS, sale                             -> sold, warranty starts
 *   releaseSale()    void or delete a sale                 -> back in_stock
 *   returnToStock()  customer return                       -> in_stock
 *   transfer()       between branches                      -> in_stock, new branch
 *   remove()         adjustment (sub), supplier return     -> written_off / returned_to_supplier
 *
 * Every change is a row in product_serial_events, which is what the lookup
 * screen and a warranty claim read. tests/Feature/SerialServiceTest.php.
 */
class SerialService
{
    public static function isTracked($productId)
    {
        return ProductKind::hasSerials($productId);
    }

    /** The serials a line names, tidied: trimmed, blanks dropped. */
    public static function clean($serials)
    {
        if (is_string($serials)) {
            $serials = preg_split('/[\r\n,]+/', $serials);
        }

        return array_values(array_filter(array_map(function ($s) {
            return trim((string) $s);
        }, (array) $serials), function ($s) {
            return $s !== '';
        }));
    }

    private static function fail($message)
    {
        throw ValidationException::withMessages(['serials' => [$message]]);
    }

    /** As many serials as units, each once. Returns the tidied list. */
    private static function counted($productId, $serials, $baseQty)
    {
        $list = self::clean($serials);
        $name = ProductKind::name($productId);
        $units = round((float) $baseQty, 6);

        if (abs($units - round($units)) > 0.000001) {
            self::fail(sprintf('%s has serial numbers, so it is counted in whole units (%s is not a whole number).', $name, $units + 0));
        }
        if (count($list) !== (int) round($units)) {
            self::fail(sprintf('%s needs one serial number per unit: %d unit(s), %d serial number(s) given.', $name, (int) round($units), count($list)));
        }
        $dupes = array_unique(array_diff_assoc(array_map('strtoupper', $list), array_unique(array_map('strtoupper', $list))));
        if ($dupes) {
            self::fail(sprintf('Serial number %s is listed twice for %s.', reset($dupes), $name));
        }

        return $list;
    }

    private static function log($serialId, $event, $warehouseId, $refType, $refId)
    {
        DB::table('product_serial_events')->insert([
            'product_serial_id' => $serialId, 'event' => $event, 'warehouse_id' => $warehouseId,
            'reference_type' => $refType, 'reference_id' => $refId,
            'user_id' => Auth::check() ? Auth::id() : null, 'created_at' => now(),
        ]);
    }

    /** The units named, locked, each required to be in $status (and in $warehouseId when given). */
    private static function units($productId, array $list, $status, $warehouseId, $verb)
    {
        $rows = DB::table('product_serials')->where('product_id', $productId)
            ->whereIn('serial_number', $list)->lockForUpdate()->get()->keyBy(function ($r) {
                return strtoupper($r->serial_number);
            });
        $name = ProductKind::name($productId);

        foreach ($list as $serial) {
            $row = $rows->get(strtoupper($serial));
            if (! $row) {
                self::fail(sprintf('Serial number %s is not recorded for %s.', $serial, $name));
            }
            if ($row->status !== $status) {
                self::fail(sprintf('Serial number %s of %s cannot be %s: it is %s.', $serial, $name, $verb, str_replace('_', ' ', $row->status)));
            }
            if ($warehouseId !== null && (int) $row->warehouse_id !== (int) $warehouseId) {
                $where = DB::table('warehouses')->where('id', $row->warehouse_id)->value('name') ?: 'another branch';
                self::fail(sprintf('Serial number %s of %s is at %s, not this branch.', $serial, $name, $where));
            }
        }

        return $rows->values();
    }

    // ------------------------------------------------------------------ in

    public static function receive($productId, $variantId, $warehouseId, $serials, $baseQty, $refType, $refId)
    {
        $list = self::counted($productId, $serials, $baseQty);
        $existing = DB::table('product_serials')->where('product_id', $productId)
            ->whereIn('serial_number', $list)->pluck('serial_number')->all();
        if ($existing) {
            self::fail(sprintf('Serial number %s of %s is already recorded. Each serial can only be received once.',
                $existing[0], ProductKind::name($productId)));
        }

        foreach ($list as $serial) {
            $id = DB::table('product_serials')->insertGetId([
                'product_id' => $productId, 'product_variant_id' => $variantId, 'serial_number' => $serial,
                'status' => 'in_stock', 'warehouse_id' => $warehouseId, 'received_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::log($id, 'received', $warehouseId, $refType, $refId);
        }
    }

    public static function returnToStock($productId, $warehouseId, $serials, $baseQty, $refType, $refId, $clientId = null)
    {
        $list = self::counted($productId, $serials, $baseQty);
        $rows = self::units($productId, $list, 'sold', null, 'taken back');

        // A customer can only bring back what was sold to them; otherwise one
        // customer is refunded for another's unit and its warranty is lost.
        if ($clientId !== null) {
            foreach ($rows as $row) {
                if ((int) $row->client_id !== (int) $clientId) {
                    self::fail(sprintf('Serial number %s of %s was sold to another customer (%s), so it cannot be returned by %s.',
                        $row->serial_number, ProductKind::name($productId),
                        DB::table('sales')->where('id', $row->sale_id)->value('Ref') ?: 'an earlier sale',
                        DB::table('clients')->where('id', $clientId)->value('name') ?: 'this customer'));
                }
            }
        }

        foreach ($rows as $row) {
            DB::table('product_serials')->where('id', $row->id)->update([
                'status' => 'in_stock', 'warehouse_id' => $warehouseId, 'updated_at' => now(),
            ]);
            self::log($row->id, 'returned', $warehouseId, $refType, $refId);
        }
    }

    // ------------------------------------------------------------------ out

    public static function sell($productId, $warehouseId, $serials, $baseQty, $saleId, $clientId)
    {
        $list = self::counted($productId, $serials, $baseQty);
        $months = ProductKind::warrantyMonths($productId);

        foreach (self::units($productId, $list, 'in_stock', $warehouseId, 'sold') as $row) {
            DB::table('product_serials')->where('id', $row->id)->update([
                'status' => 'sold', 'warehouse_id' => null, 'sale_id' => $saleId, 'client_id' => $clientId,
                'sold_at' => now(), 'warranty_until' => $months ? Carbon::today()->addMonths($months)->toDateString() : null,
                'updated_at' => now(),
            ]);
            self::log($row->id, 'sold', $warehouseId, 'Sale', $saleId);
        }
    }

    /** A sale voided or deleted: its units go back on the shelf they left. */
    public static function releaseSale($saleId, $reason = 'released')
    {
        $warehouseId = DB::table('sales')->where('id', $saleId)->value('warehouse_id');
        $rows = DB::table('product_serials')->where('sale_id', $saleId)->where('status', 'sold')->lockForUpdate()->get();
        foreach ($rows as $row) {
            DB::table('product_serials')->where('id', $row->id)->update([
                'status' => 'in_stock', 'warehouse_id' => $warehouseId, 'sale_id' => null, 'client_id' => null,
                'sold_at' => null, 'warranty_until' => null, 'updated_at' => now(),
            ]);
            self::log($row->id, $reason, $warehouseId, 'Sale', $saleId);
        }

        return $rows->count();
    }

    public static function transfer($productId, $fromId, $toId, $serials, $baseQty, $transferId)
    {
        $list = self::counted($productId, $serials, $baseQty);
        foreach (self::units($productId, $list, 'in_stock', $fromId, 'moved') as $row) {
            DB::table('product_serials')->where('id', $row->id)->update(['warehouse_id' => $toId, 'updated_at' => now()]);
            self::log($row->id, 'transferred', $toId, 'Transfer', $transferId);
        }
    }

    /** Written off (adjustment) or sent back to the supplier. */
    public static function remove($productId, $warehouseId, $serials, $baseQty, $status, $refType, $refId)
    {
        $list = self::counted($productId, $serials, $baseQty);
        foreach (self::units($productId, $list, 'in_stock', $warehouseId, 'removed') as $row) {
            DB::table('product_serials')->where('id', $row->id)->update([
                'status' => $status, 'warehouse_id' => null, 'updated_at' => now(),
            ]);
            self::log($row->id, $status, $warehouseId, $refType, $refId);
        }
    }

    // ------------------------------------------------------------------ guards and lookups

    /**
     * Refuse a path that cannot carry serials (editing a saved document, a
     * stock count, production) when any line is serial-tracked.
     */
    public static function refuseIfTracked(array $productIds, $what, $hint = null)
    {
        foreach (array_unique(array_filter($productIds)) as $id) {
            if (self::isTracked($id)) {
                self::fail(sprintf('%s has serial numbers, so it cannot go through %s. %s',
                    ProductKind::name($id), $what,
                    $hint ?: 'Use a return, a void, a transfer or an adjustment, which name the units.'));
            }
        }
    }

    /** A transfer deleted: its units go back to the branch they came from. */
    public static function undoTransfer($transferId, $fromWarehouseId)
    {
        $events = DB::table('product_serial_events')->where('event', 'transferred')
            ->where('reference_type', 'Transfer')->where('reference_id', $transferId)->get();
        foreach ($events as $e) {
            $row = DB::table('product_serials')->where('id', $e->product_serial_id)->first();
            if (! $row || $row->status !== 'in_stock' || (int) $row->warehouse_id !== (int) $e->warehouse_id) {
                self::fail(sprintf('Serial number %s from this transfer has been sold or moved since, so the transfer cannot be undone.', $row ? $row->serial_number : '?'));
            }
            DB::table('product_serials')->where('id', $row->id)->update(['warehouse_id' => $fromWarehouseId, 'updated_at' => now()]);
            DB::table('product_serial_events')->where('id', $e->id)->delete();
        }

        return $events->count();
    }

    /** A customer return deleted: the units it took back are sold again. */
    public static function undoReturn($refType, $refId)
    {
        $events = DB::table('product_serial_events')->where('event', 'returned')
            ->where('reference_type', $refType)->where('reference_id', $refId)->get();
        foreach ($events as $e) {
            $row = DB::table('product_serials')->where('id', $e->product_serial_id)->first();
            if ($row && $row->status !== 'in_stock') {
                self::fail(sprintf('Serial number %s from this return has been sold or moved again, so the return cannot be undone.', $row->serial_number));
            }
            DB::table('product_serials')->where('id', $e->product_serial_id)
                ->update(['status' => 'sold', 'warehouse_id' => null, 'updated_at' => now()]);
            DB::table('product_serial_events')->where('id', $e->id)->delete();
        }

        return $events->count();
    }

    /**
     * Undo what a document took off the shelf (an adjustment's write-off, a
     * supplier return, being deleted): those units are back where they were.
     */
    public static function undoRemoval($refType, $refId)
    {
        $events = DB::table('product_serial_events')->whereIn('event', ['written_off', 'returned_to_supplier'])
            ->where('reference_type', $refType)->where('reference_id', $refId)->get();
        foreach ($events as $e) {
            DB::table('product_serials')->where('id', $e->product_serial_id)
                ->update(['status' => 'in_stock', 'warehouse_id' => $e->warehouse_id, 'updated_at' => now()]);
            DB::table('product_serial_events')->where('id', $e->id)->delete();
        }

        return $events->count();
    }

    /**
     * Undo what a document received (a goods receipt or an adjustment being
     * deleted): its units are forgotten, unless one has moved on since.
     */
    public static function undoReceipt($refType, $refId)
    {
        $ids = DB::table('product_serial_events')->where('event', 'received')
            ->where('reference_type', $refType)->where('reference_id', $refId)->pluck('product_serial_id')->all();
        if (! $ids) {
            return 0;
        }
        $moved = DB::table('product_serials')->whereIn('id', $ids)->where('status', '!=', 'in_stock')->value('serial_number');
        if ($moved) {
            self::fail(sprintf('Serial number %s from this document has already been sold or moved, so it cannot be undone.', $moved));
        }
        DB::table('product_serial_events')->whereIn('product_serial_id', $ids)->delete();
        DB::table('product_serials')->whereIn('id', $ids)->delete();

        return count($ids);
    }

    /** Units on the shelf of one branch, for the picker. */
    public static function available($productId, $warehouseId, $variantId = null)
    {
        return DB::table('product_serials')->where('product_id', $productId)->where('status', 'in_stock')
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, function ($q) use ($variantId) { $q->where('product_variant_id', $variantId); })
            ->orderBy('received_at')->orderBy('serial_number')
            ->pluck('serial_number')->all();
    }

    /** Everything known about a serial number, newest event first. */
    public static function lookup($serial)
    {
        $rows = DB::table('product_serials as s')->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoin('sales as sa', 'sa.id', '=', 's.sale_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.serial_number', trim((string) $serial))
            ->select('s.id', 's.serial_number', 's.status', 's.received_at', 's.sold_at', 's.warranty_until',
                'p.name as product', 'p.code as product_code', 'p.warranty_months', 'w.name as warehouse',
                'sa.Ref as sale_ref', 'sa.id as sale_id', 'c.name as customer')
            ->get();

        return $rows->map(function ($r) {
            $r->under_warranty = $r->warranty_until !== null && $r->warranty_until >= now()->toDateString();
            $r->history = DB::table('product_serial_events as e')
                ->leftJoin('warehouses as w', 'w.id', '=', 'e.warehouse_id')
                ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.product_serial_id', $r->id)
                ->orderByDesc('e.id')
                ->get(['e.event', 'e.created_at', 'w.name as warehouse', 'e.reference_type', 'e.reference_id', 'u.username as by']);

            return $r;
        })->values();
    }
}
