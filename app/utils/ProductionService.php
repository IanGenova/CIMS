<?php

namespace App\utils;

use App\Models\product_warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning components into finished goods.
 *
 * Completing a production order consumes the recipe's components and produces
 * the finished product. Both sides go through the same stock paths everything
 * else uses, so a batch-tracked component is picked oldest-expiry-first and a
 * batch-tracked output gets its own lot -- the ledger stays exact either way.
 */
class ProductionService
{
    /**
     * What one order needs, per component, including waste.
     *
     * @return array list of ['product_id','name','code','required','available','short']
     */
    public static function requirements($bomId, $quantity, $warehouseId)
    {
        $bom = DB::table('boms')->whereNull('deleted_at')->where('id', $bomId)->first();
        if (! $bom) {
            throw ValidationException::withMessages(['bom' => ['That bill of materials does not exist.']]);
        }

        $output = (float) $bom->output_quantity;
        if ($output <= 0) {
            throw ValidationException::withMessages([
                'bom' => ['This recipe has an output quantity of zero, so it cannot be scaled.'],
            ]);
        }

        // How many times the recipe must be run to reach the target quantity.
        $runs = (float) $quantity / $output;

        $items = DB::table('bom_items as bi')
            ->join('products as p', 'p.id', '=', 'bi.product_id')
            ->where('bi.bom_id', $bomId)
            ->select('bi.product_id', 'bi.quantity', 'bi.waste_percent', 'p.name', 'p.code', 'p.cost')
            ->get();

        $out = [];
        foreach ($items as $i) {
            $nominal = (float) $i->quantity * $runs;
            $required = round($nominal * (1 + ((float) $i->waste_percent / 100)), 6);

            $available = (float) product_warehouse::where('deleted_at', '=', null)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $i->product_id)
                ->sum('qte');

            $out[] = [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'code' => $i->code,
                'unit_quantity' => (float) $i->quantity,
                'waste_percent' => (float) $i->waste_percent,
                'required' => $required,
                'available' => round($available, 6),
                'short' => round(max(0, $required - $available), 6),
                'unit_cost' => (float) $i->cost,
                'line_cost' => round($required * (float) $i->cost, 4),
            ];
        }

        return $out;
    }

    /**
     * Complete an order: consume components, produce the finished good, and
     * stamp what it actually cost.
     */
    public static function complete($orderId, array $outputBatch = null)
    {
        return DB::transaction(function () use ($orderId, $outputBatch) {
            $order = DB::table('production_orders')->where('id', $orderId)
                ->whereNull('deleted_at')->lockForUpdate()->first();

            if (! $order) {
                throw ValidationException::withMessages(['order' => ['That production order does not exist.']]);
            }

            if ($order->status === 'completed') {
                throw ValidationException::withMessages([
                    'status' => ['This order has already been completed.'],
                ]);
            }

            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => ['A cancelled order cannot be completed.'],
                ]);
            }

            // An order saved against a service (or variant) output before recipes
            // refused them would consume components and produce nothing (DATATEST K23).
            $output = DB::table('products')->where('id', $order->product_id)->first(['name', 'is_service', 'is_variant']);
            if ($output && ($output->is_service || $output->is_variant)) {
                throw ValidationException::withMessages(['product_id' => [
                    "{$output->name} cannot be produced: " . ($output->is_service ? 'it is a service.' : 'the recipe names no variant.'),
                ]]);
            }

            // A build moves units by quantity and cannot name serial numbers.
            SerialService::refuseIfTracked(array_merge([$order->product_id],
                DB::table('bom_items')->where('bom_id', $order->bom_id)->pluck('product_id')->all()), 'production');

            $requirements = self::requirements($order->bom_id, $order->quantity, $order->warehouse_id);

            // Refuse the whole order if anything is short: a half-built assembly
            // with components already consumed is worse than not starting.
            $short = array_filter($requirements, function ($r) {
                return $r['short'] > 0;
            });
            if (! empty($short)) {
                $names = implode(', ', array_map(function ($r) {
                    return $r['name'] . ' (short ' . rtrim(rtrim(number_format($r['short'], 6, '.', ''), '0'), '.') . ')';
                }, $short));

                throw ValidationException::withMessages([
                    'stock' => ['Not enough stock to build this order: ' . $names . '.'],
                ]);
            }

            $totalCost = 0.0;

            foreach ($requirements as $r) {
                self::consume($r['product_id'], $order->warehouse_id, $r['required'], $orderId);

                $lineCost = round($r['required'] * $r['unit_cost'], 4);
                $totalCost += $lineCost;

                DB::table('production_consumptions')->insert([
                    'production_order_id' => $orderId,
                    'product_id' => $r['product_id'],
                    'quantity' => $r['required'],
                    'unit_cost' => $r['unit_cost'],
                    'total_cost' => $lineCost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $qty = (float) $order->quantity;
            $unitCost = $qty > 0 ? round($totalCost / $qty, 4) : 0.0;

            // The finished product's cost becomes the weighted average of the
            // stock already on hand (at its current cost) and what was just
            // built (at the build cost), so stock valuation and profit use what
            // it really cost to make. Read before the new units land; stock
            // that had gone negative counts as none (ProductionCostTest).
            self::averageCost($order->product_id, $qty, $unitCost);

            self::produce($order->product_id, $order->warehouse_id, $qty, $orderId, $unitCost, $outputBatch);

            DB::table('production_orders')->where('id', $orderId)->update([
                'status' => 'completed',
                'total_cost' => round($totalCost, 4),
                'unit_cost' => $unitCost,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['total_cost' => round($totalCost, 4), 'unit_cost' => $unitCost];
        });
    }

    // ---------------------------------------------------------------

    /** Moving average of the product's cost over all warehouses. */
    private static function averageCost($productId, $builtQty, $builtUnitCost)
    {
        if ($builtQty <= 0) {
            return;
        }

        $onHand = max(0.0, (float) DB::table('product_warehouse')
            ->whereNull('deleted_at')
            ->where('product_id', $productId)
            ->sum('qte'));
        $current = (float) DB::table('products')->where('id', $productId)->value('cost');

        $average = ($onHand * $current + $builtQty * $builtUnitCost) / ($onHand + $builtQty);

        DB::table('products')->where('id', $productId)
            ->update(['cost' => round($average, 4), 'updated_at' => now()]);
    }

    /** Take a component out of stock, batch-aware. */
    private static function consume($productId, $warehouseId, $qty, $orderId)
    {
        if ($qty <= 0) {
            return;
        }

        if (BatchService::isTracked($productId)) {
            BatchService::allocate(
                $productId, null, $warehouseId, $qty,
                'production_consume', 'ProductionOrder', $orderId
            );

            return;
        }

        $row = product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->whereNull('product_variant_id')
            ->lockForUpdate()->first();

        if (! $row || (float) $row->qte < $qty - 0.000001) {
            throw ValidationException::withMessages([
                'stock' => ['Stock for a component moved while this order was being completed. Try again.'],
            ]);
        }

        $row->qte -= $qty;
        $row->save();
    }

    /** Put the finished goods into stock, batch-aware. */
    private static function produce($productId, $warehouseId, $qty, $orderId, $unitCost, array $outputBatch = null)
    {
        if ($qty <= 0) {
            return;
        }

        if (BatchService::isTracked($productId)) {
            if (empty($outputBatch['batch_number'])) {
                throw ValidationException::withMessages([
                    'batch_number' => ['This product is batch tracked, so the finished goods need a lot number.'],
                ]);
            }

            $batch = BatchService::resolveBatch([
                'product_id' => $productId,
                'product_variant_id' => null,
                'batch_number' => $outputBatch['batch_number'],
                'manufacture_date' => isset($outputBatch['manufacture_date']) ? $outputBatch['manufacture_date'] : date('Y-m-d'),
                'expiry_date' => isset($outputBatch['expiry_date']) ? $outputBatch['expiry_date'] : null,
                'cost' => $unitCost,
            ]);

            BatchService::receive(
                $batch->id, $warehouseId, $qty,
                'production_output', 'ProductionOrder', $orderId
            );

            return;
        }

        $row = product_warehouse::where('deleted_at', '=', null)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->whereNull('product_variant_id')
            ->lockForUpdate()->first();

        if ($row) {
            $row->qte += $qty;
            $row->save();
        } else {
            product_warehouse::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'product_variant_id' => null,
                'qte' => $qty,
            ]);
        }
    }
}
