<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Guards the one-to-one conversion of a fully reserved customer order into a sale. */
class CustomerOrderService
{
    public static function cancel($orderId, $userId = null, $reason = 'Customer order cancelled')
    {
        DB::transaction(function () use ($orderId, $userId, $reason) {
            $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
            abort_unless($order, 404);
            if ($order->status === 'cancelled') return;
            if ($order->sale_id || $order->status === 'fulfilled') {
                self::fail('A fulfilled order cannot be cancelled; use the sale return workflow.');
            }
            if (DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
                ->where('status', 'paid')->exists()) {
                self::fail('This order has been paid; contact the store for a refund.');
            }
            if (DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
                ->whereIn('status', ['pending', 'creating', 'review'])->exists()) {
                self::fail('An online payment is still processing. Check its status before cancelling.');
            }
            $lines = DB::table('customer_order_lines')->where('customer_order_id', $orderId)->lockForUpdate()->get();
            foreach ($lines as $line) {
                if ($line->status === 'reserved') {
                    ReservationService::close('customer_order_line', $line->id, 'released', $userId, $reason);
                }
            }
            DB::table('customer_order_lines')->where('customer_order_id', $orderId)->update([
                'status' => 'cancelled', 'updated_at' => now(),
            ]);
            DB::table('customer_orders')->where('id', $orderId)->update([
                'status' => 'cancelled', 'updated_at' => now(),
            ]);
            self::notifyStatus($orderId);
        }, 5);
    }

    private static function fail($message)
    {
        throw ValidationException::withMessages(['customer_order' => [$message]]);
    }

    private static function baseQuantity($detail)
    {
        $unit = DB::table('units')->where('id', $detail['sale_unit_id'])->first();
        if (! $unit || (float) $unit->operator_value <= 0) {
            self::fail('The selected sale unit is invalid.');
        }
        return $unit->operator === '/'
            ? (float) $detail['quantity'] / (float) $unit->operator_value
            : (float) $detail['quantity'] * (float) $unit->operator_value;
    }

    public static function validateSale($orderId, $clientId, $warehouseId, array $details)
    {
        $order = DB::table('customer_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (! $order || $order->status !== 'reserved' || $order->sale_id
            || (int) $order->client_id !== (int) $clientId
            || (int) $order->warehouse_id !== (int) $warehouseId) {
            self::fail('Select a fully reserved customer order for this customer and warehouse.');
        }
        if ($order->source_channel === 'web' && $order->checkout_payment_method === 'xendit'
            && ! DB::table('shop_payment_sessions')->where('customer_order_id', $orderId)
                ->where('status', 'paid')->exists()) {
            self::fail('Verify the online payment before fulfilling this Xendit order.');
        }
        $lines = DB::table('customer_order_lines')->where('customer_order_id', $orderId)
            ->lockForUpdate()->get()->keyBy('id');
        if ($lines->count() !== count($details)) {
            self::fail('The sale must include every reserved order line exactly once.');
        }
        $seen = [];
        foreach ($details as $detail) {
            $lineId = (int) ($detail['customer_order_line_id'] ?? 0);
            $line = $lines->get($lineId);
            if (! $line || isset($seen[$lineId]) || $line->status !== 'reserved'
                || (int) $line->product_id !== (int) $detail['product_id']
                || ($line->product_variant_id === null ? null : (int) $line->product_variant_id)
                    !== (isset($detail['product_variant_id']) ? (int) $detail['product_variant_id'] : null)
                || abs((float) $line->quantity - self::baseQuantity($detail)) > 0.000001) {
                self::fail('Sale product, variant, and base quantity must match each reserved order line.');
            }
            if ($order->source_channel === 'web' && $line->unit_price !== null) {
                $saleUnit = DB::table('products')->where('id', $line->product_id)->value('unit_sale_id');
                if ((int) $detail['sale_unit_id'] !== (int) $saleUnit
                    || abs((float) $detail['Unit_price'] - (float) $line->unit_price) > 0.0001) {
                    self::fail('The web order must use its quoted sale unit and unit price.');
                }
            }
            $seen[$lineId] = true;
        }
        return $order;
    }

    /** Close this line's promise inside the sale transaction and return its exact sources. */
    public static function consumeLine($lineId, $saleId, $serialNumbers = [])
    {
        $rows = DB::table('stock_reservations')->where('owner_type', 'customer_order_line')
            ->where('owner_id', $lineId)->where('status', 'active')->lockForUpdate()->get();
        if ($rows->isEmpty()) self::fail('This order line no longer has an active stock reservation.');
        $reservedSerials = DB::table('product_serials')
            ->whereIn('id', $rows->pluck('product_serial_id')->filter())->pluck('serial_number')
            ->map(fn ($number) => strtoupper($number))->sort()->values()->all();
        if ($reservedSerials) {
            $submitted = collect($serialNumbers)->map(fn ($number) => strtoupper(trim($number)))
                ->sort()->values()->all();
            if ($reservedSerials !== $submitted) {
                self::fail('The sale must use the serial numbers reserved for this customer.');
            }
        }
        foreach ($rows as $row) {
            if ($row->product_batch_id && DB::table('product_batches')
                ->where('id', $row->product_batch_id)
                ->whereDate('expiry_date', '<', now()->toDateString())->exists()) {
                self::fail('A reserved lot expired; release and reallocate the order before selling it.');
            }
        }
        ReservationService::close('customer_order_line', $lineId, 'consumed', auth('api')->id(), 'Converted to sale ' . $saleId);
        DB::table('customer_order_lines')->where('id', $lineId)->update([
            'status' => 'fulfilled', 'updated_at' => now(),
        ]);
        return $rows;
    }

    public static function finishSale($orderId, $saleId)
    {
        $changed = DB::table('customer_orders')->where('id', $orderId)->where('status', 'reserved')
            ->whereNull('sale_id')->update([
                'status' => 'fulfilled', 'sale_id' => $saleId, 'updated_at' => now(),
            ]);
        if ($changed) self::notifyStatus($orderId);
    }

    /** Queue a signed delivery; the website can retry or query status by ID. */
    public static function notifyStatus($orderId)
    {
        $order = DB::table('customer_orders')->where('id', $orderId)->first();
        if (! $order) return;
        $lines = DB::table('customer_order_lines')->where('customer_order_id', $orderId)
            ->orderBy('id')->get(['id', 'product_id', 'product_variant_id', 'quantity', 'status']);
        WebhookService::dispatch('customer_order.status', [
            'id' => $order->id, 'reference' => $order->Ref,
            'source_channel' => $order->source_channel,
            'external_order_id' => $order->external_order_id,
            'warehouse_id' => $order->warehouse_id,
            'status' => $order->status, 'sale_id' => $order->sale_id,
            'updated_at' => $order->updated_at,
            'lines' => $lines,
        ]);
    }
}
