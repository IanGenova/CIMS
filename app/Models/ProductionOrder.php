<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use SoftDeletes;
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;

    protected $table = 'production_orders';

    protected $fillable = [
        'Ref', 'bom_id', 'product_id', 'warehouse_id', 'date', 'quantity',
        'status', 'total_cost', 'unit_cost', 'completed_at', 'notes', 'user_id',
    ];

    protected $casts = [
        'bom_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_id' => 'integer',
        'date' => 'date',
        'quantity' => 'double',
        'total_cost' => 'double',
        'unit_cost' => 'double',
    ];

    public function bom()
    {
        return $this->belongsTo('App\Models\Bom', 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function consumptions()
    {
        return $this->hasMany('App\Models\ProductionConsumption', 'production_order_id');
    }
}
