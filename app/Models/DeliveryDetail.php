<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryDetail extends Model
{
    protected $table = 'delivery_details';

    protected $fillable = [
        'delivery_id', 'sale_detail_id', 'product_id', 'product_variant_id', 'quantity',
    ];

    protected $casts = [
        'delivery_id' => 'integer',
        'sale_detail_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'double',
    ];

    public function delivery()
    {
        return $this->belongsTo('App\Models\Delivery');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
