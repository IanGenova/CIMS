<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use SoftDeletes;

    protected $table = 'boms';

    protected $fillable = [
        'name', 'product_id', 'output_quantity', 'is_active', 'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'output_quantity' => 'double',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function items()
    {
        return $this->hasMany('App\Models\BomItem', 'bom_id');
    }
}
