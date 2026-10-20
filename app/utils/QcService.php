<?php

namespace App\utils;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class QcService
{
    public static function isRequired($productId)
    {
        return (bool) Product::where('id', $productId)->value('requires_qc');
    }

    public static function refuseDirectReceipt(array $productIds)
    {
        $name = Product::whereIn('id', array_filter($productIds))
            ->where('requires_qc', 1)->value('name');

        if ($name) {
            throw ValidationException::withMessages([
                'statut' => [sprintf(
                    '"%s" requires quality inspection. Save the purchase as ordered, then use a Goods Receipt and QC approval before the stock can be sold.',
                    $name
                )],
            ]);
        }
    }
}
