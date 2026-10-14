<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BatchStock extends Model
{
    protected $table = 'batch_stock';

    protected $fillable = ['product_batch_id', 'warehouse_id', 'qte'];

    protected $casts = [
        'product_batch_id' => 'integer',
        'warehouse_id' => 'integer',
        'qte' => 'double',
    ];

    protected static function booted()
    {
        static::saving(function (self $stock) {
            // Same guard the warehouse-level stock model uses: absorb floating
            // point noise, then refuse to go negative.
            if (abs((float) $stock->qte) < 0.000001) {
                $stock->qte = 0;
            }

            if ((float) $stock->qte < 0) {
                throw ValidationException::withMessages([
                    'stock' => ['Insufficient stock in the selected batch.'],
                ]);
            }
        });
    }

    public function batch()
    {
        return $this->belongsTo('App\Models\ProductBatch', 'product_batch_id');
    }

    // Stock levels name every warehouse, even ones the viewer is not on.
    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse')
            ->withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class);
    }
}
