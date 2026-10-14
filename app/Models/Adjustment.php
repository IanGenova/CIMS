<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Adjustment extends Model
{
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'user_id', 'warehouse_id',
        'items', 'notes', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'warehouse_id' => 'integer',
        // items is DECIMAL in the database; cast so it stays a number in JSON.
        'items' => 'double',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\AdjustmentDetail');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

}
