<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierProduct extends Model
{
    use SoftDeletes;

    protected $table = 'supplier_products';

    protected $fillable = [
        'provider_id', 'product_id', 'supplier_sku',
        'cost', 'lead_time_days', 'min_order_qty', 'is_preferred',
    ];

    protected $casts = [
        'provider_id' => 'integer',
        'product_id' => 'integer',
        'cost' => 'double',
        'lead_time_days' => 'integer',
        'min_order_qty' => 'double',
        'is_preferred' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
