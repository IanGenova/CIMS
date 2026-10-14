<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseLocation extends Model
{
    use SoftDeletes;

    protected $table = 'warehouse_locations';

    protected $fillable = [
        'warehouse_id', 'code', 'zone', 'aisle', 'rack', 'bin',
        'is_receiving', 'is_active', 'pick_sequence',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'is_receiving' => 'boolean',
        'is_active' => 'boolean',
        'pick_sequence' => 'integer',
    ];

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function stock()
    {
        return $this->hasMany('App\Models\LocationStock', 'location_id');
    }
}
