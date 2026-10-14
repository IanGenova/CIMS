<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionConsumption extends Model
{
    protected $table = 'production_consumptions';

    protected $fillable = [
        'production_order_id', 'product_id', 'quantity', 'unit_cost', 'total_cost',
    ];

    protected $casts = [
        'production_order_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'double',
        'unit_cost' => 'double',
        'total_cost' => 'double',
    ];

    public function order()
    {
        return $this->belongsTo('App\Models\ProductionOrder', 'production_order_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
