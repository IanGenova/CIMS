<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationStock extends Model
{
    protected $table = 'location_stock';

    protected $fillable = [
        'location_id', 'product_id', 'product_variant_id', 'warehouse_id', 'qte',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'qte' => 'double',
    ];

    public function location()
    {
        return $this->belongsTo('App\Models\WarehouseLocation', 'location_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
