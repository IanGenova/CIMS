<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    protected $table = 'bom_items';

    protected $fillable = ['bom_id', 'product_id', 'quantity', 'waste_percent'];

    protected $casts = [
        'bom_id' => 'integer',
        'product_id' => 'integer',
        'quantity' => 'double',
        'waste_percent' => 'double',
    ];

    public function bom()
    {
        return $this->belongsTo('App\Models\Bom', 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }
}
