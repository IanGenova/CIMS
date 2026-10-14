<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
    // A branch's own transfers are the ones leaving from OR arriving at it
    // (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected static $warehouseColumns = ['from_warehouse_id', 'to_warehouse_id'];

    protected $table = 'transfers';
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'id', 'date','user_id', 'from_warehouse_id', 'to_warehouse_id',
        'items', 'statut', 'notes', 'GrandTotal', 'discount', 'shipping', 'TaxNet', 'tax_rate',
        'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'from_warehouse_id' => 'integer',
        'to_warehouse_id' => 'integer',
        'items' => 'double',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',

    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\TransferDetail');
    }

    // Both ends always named, even the branch the viewer is not on.
    public function from_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'from_warehouse_id')
            ->withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class);
    }

    public function to_warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse', 'to_warehouse_id')
            ->withoutGlobalScope(\App\Models\Scopes\UserWarehouseScope::class);
    }

}
