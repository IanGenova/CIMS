<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Physical rejected stock is counted separately from sellable warehouse stock. */
class QcCustodyService
{
    private static function fail($message)
    {
        throw ValidationException::withMessages(['custody' => [$message]]);
    }

    public static function recordFailure($sourceType, $sourceId, $warehouseId, $productId,
        $variantId, $batchId, array $serialIds, $baseQuantity, $location, $actorId, $note)
    {
        if ($baseQuantity <= 0) return;
        if ($serialIds && abs(count($serialIds) - $baseQuantity) > 0.000001) {
            self::fail('The failed serial count does not match the rejected quantity.');
        }
        foreach ($serialIds ?: [null] as $serialId) {
            $quantity = $serialId ? 1 : $baseQuantity;
            $id = DB::table('qc_rejected_custody')->insertGetId([
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'warehouse_id' => $warehouseId, 'product_id' => $productId,
                'product_variant_id' => $variantId, 'product_batch_id' => $batchId,
                'product_serial_id' => $serialId,
                'original_quantity' => $quantity, 'remaining_quantity' => $quantity,
                'location' => $location, 'status' => 'held', 'received_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::event($id, 'held', $quantity, null, $location, $sourceType,
                $sourceId, $actorId, $note);
        }
    }

    public static function event($custodyId, $event, $quantity, $from, $to,
        $referenceType, $referenceId, $actorId, $note)
    {
        DB::table('qc_custody_events')->insert([
            'custody_id' => $custodyId, 'event' => $event, 'quantity' => $quantity,
            'from_location' => $from, 'to_location' => $to,
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'actor_id' => $actorId, 'note' => $note, 'created_at' => now(),
        ]);
    }

    /** Consume only named rejected serials, or FIFO quantity for a nonserial source. */
    public static function execute($sourceType, $sourceId, $baseQuantity, array $serialIds,
        $event, $dispositionId, $actorId, $note)
    {
        $rows = DB::table('qc_rejected_custody')
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('status', 'held')->orderBy('id')->lockForUpdate()->get();
        if ($serialIds) {
            $rows = $rows->filter(fn ($row) => in_array((int) $row->product_serial_id,
                array_map('intval', $serialIds), true));
        }
        if (abs((float) $rows->sum('remaining_quantity') - $baseQuantity) > 0.000001
            && ($serialIds || (float) $rows->sum('remaining_quantity') < $baseQuantity - 0.000001)) {
            self::fail('The physical rejected-goods balance no longer matches this disposition.');
        }
        $remaining = $baseQuantity;
        foreach ($rows as $row) {
            if ($remaining <= 0.000001) break;
            $amount = min($remaining, (float) $row->remaining_quantity);
            $left = max(0, (float) $row->remaining_quantity - $amount);
            DB::table('qc_rejected_custody')->where('id', $row->id)->update([
                'remaining_quantity' => $left,
                'status' => $left <= 0.000001 ? 'cleared' : 'held',
                'updated_at' => now(),
            ]);
            self::event($row->id, $event, $amount, $row->location, null,
                $sourceType === 'branch_shipment_item' ? 'BranchReturnQcDisposition' : 'QcDisposition',
                $dispositionId, $actorId, $note);
            $remaining -= $amount;
        }
        if ($remaining > 0.000001) self::fail('The rejected-goods balance is insufficient.');
    }
}
