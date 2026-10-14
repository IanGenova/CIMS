<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockCount extends Model
{
    use SoftDeletes;
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;

    protected $table = 'stock_counts';

    protected $fillable = [
        'Ref', 'date', 'warehouse_id', 'user_id', 'status', 'category_id',
        'notes', 'completed_at', 'completed_by',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'user_id' => 'integer',
        'category_id' => 'integer',
        'completed_by' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function details()
    {
        return $this->hasMany('App\Models\StockCountDetail', 'stock_count_id');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }
}
