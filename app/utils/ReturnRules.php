<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A return takes back what its document actually moved, and no more
 * (DATATEST K5; owner decision in .flow/datatest/decisions.md).
 *
 * A customer return names the sale it comes from: that customer's completed
 * sale, each line capped at what was sold less what already came back. A
 * supplier return may name its purchase; when it does, the same cap applies
 * against what was received.
 */
class ReturnRules
{
    public static function forSale($saleId, $clientId, $details, $excludeReturnId = null)
    {
        $sale = $saleId ? DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->first() : null;
        if (! $sale) {
            self::fail('sale_id', 'Choose the sale this return comes from.');
        }
        if ((int) $sale->client_id !== (int) $clientId) {
            self::fail('sale_id', "Sale {$sale->Ref} was not made to this customer.");
        }
        if ($sale->statut !== 'completed') {
            self::fail('sale_id', "Sale {$sale->Ref} is {$sale->statut}; only a completed sale can be returned.");
        }

        $sold = self::baseByLine(DB::table('sale_details')->where('sale_id', $saleId)->get(), 'sale_unit_id');
        $returned = self::baseByLine(DB::table('sale_return_details as d')
            ->join('sale_returns as r', 'r.id', '=', 'd.sale_return_id')
            ->where('r.sale_id', $saleId)->whereNull('r.deleted_at')
            ->when($excludeReturnId, fn ($q) => $q->where('r.id', '!=', $excludeReturnId))
            ->get(['d.product_id', 'd.product_variant_id', 'd.quantity', 'd.sale_unit_id']), 'sale_unit_id');

        self::cap($details, 'sale_unit_id', $sold, $returned, "sale {$sale->Ref}");
    }

    public static function forPurchase($purchaseId, $providerId, $details, $excludeReturnId = null)
    {
        $purchase = DB::table('purchases')->where('id', $purchaseId)->whereNull('deleted_at')->first();
        if (! $purchase) {
            self::fail('purchase_id', 'That purchase does not exist.');
        }
        if ((int) $purchase->provider_id !== (int) $providerId) {
            self::fail('purchase_id', "Purchase {$purchase->Ref} is from another supplier.");
        }

        $lines = DB::table('purchase_details')->where('purchase_id', $purchaseId)->get()->map(function ($l) use ($purchase) {
            // What arrived: the received quantity (purchases saved "received"
            // before receipts were recorded carry it on the ordered quantity).
            $l->quantity = (float) $l->received_qte > 0 ? (float) $l->received_qte
                : ($purchase->statut === 'received' ? (float) $l->quantity : 0.0);

            return $l;
        });
        $received = self::baseByLine($lines, 'purchase_unit_id');
        $returned = self::baseByLine(DB::table('purchase_return_details as d')
            ->join('purchase_returns as r', 'r.id', '=', 'd.purchase_return_id')
            ->where('r.purchase_id', $purchaseId)->whereNull('r.deleted_at')->whereNull('d.deleted_at')
            ->when($excludeReturnId, fn ($q) => $q->where('r.id', '!=', $excludeReturnId))
            ->get(['d.product_id', 'd.product_variant_id', 'd.quantity', 'd.purchase_unit_id']), 'purchase_unit_id');

        self::cap($details, 'purchase_unit_id', $received, $returned, "purchase {$purchase->Ref}");
    }

    /** Base-unit quantities per product/variant. */
    private static function baseByLine($rows, $unitKey)
    {
        $out = [];
        foreach ($rows as $r) {
            $unit = $r->{$unitKey} ? DB::table('units')->where('id', $r->{$unitKey})->first() : null;
            $key = $r->product_id . '|' . ($r->product_variant_id ?: '');
            $out[$key] = ($out[$key] ?? 0) + ($unit ? LineRules::toBase((float) $r->quantity, $unit) : (float) $r->quantity);
        }

        return $out;
    }

    private static function cap($details, $unitKey, array $had, array $back, $what)
    {
        $asked = [];
        foreach (array_values((array) $details) as $i => $line) {
            $key = $line['product_id'] . '|' . (($line['product_variant_id'] ?? null) ?: '');
            $unit = ! empty($line[$unitKey]) ? DB::table('units')->where('id', $line[$unitKey])->first() : null;
            $asked[$key] = ($asked[$key] ?? 0) + ($unit ? LineRules::toBase((float) $line['quantity'], $unit) : (float) $line['quantity']);
            $name = DB::table('products')->where('id', $line['product_id'])->value('name');

            if (! isset($had[$key])) {
                self::fail("details.$i", "{$name} is not on {$what}.");
            }
            $left = round($had[$key] - ($back[$key] ?? 0), 6);
            if ($asked[$key] > $left + 0.000001) {
                self::fail("details.$i", "{$name}: only " . rtrim(rtrim(number_format(max(0, $left), 6, '.', ''), '0'), '.')
                    . " can still come back on {$what}.");
            }
        }
    }

    private static function fail($key, $message)
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
