<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountDetail extends Model
{
    protected $table = 'stock_count_details';

    protected $fillable = [
        'stock_count_id', 'product_id', 'product_variant_id',
        'product_batch_id', 'system_qte', 'counted_qte', 'notes',
    ];

    protected $casts = [
        'stock_count_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'system_qte' => 'double',
        'counted_qte' => 'double',
    ];

    /** Counted minus system. Null while the line is still uncounted. */
    public function getVarianceAttribute()
    {
        if ($this->counted_qte === null) {
            return null;
        }

        return round((float) $this->counted_qte - (float) $this->system_qte, 6);
    }

    public function batch()
    {
        return $this->belongsTo('App\Models\ProductBatch', 'product_batch_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function stockCount()
    {
        return $this->belongsTo('App\Models\StockCount', 'stock_count_id');
    }
}
