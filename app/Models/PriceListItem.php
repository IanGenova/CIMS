<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $table = 'price_list_items';

    protected $fillable = [
        'price_list_id', 'product_id', 'min_quantity', 'price', 'discount_percent',
    ];

    protected $casts = [
        'price_list_id' => 'integer',
        'product_id' => 'integer',
        'min_quantity' => 'double',
        'price' => 'double',
        'discount_percent' => 'double',
    ];

    public function list()
    {
        return $this->belongsTo('App\Models\PriceList', 'price_list_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
