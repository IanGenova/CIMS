<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    // Staff tied to other branches neither list nor open it (QuotationWarehouseTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit or conversion (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'client_id', 'GrandTotal', 'warehouse_id', 'user_id', 'statut',
        'notes', 'discount', 'shipping', 'TaxNet', 'tax_rate', 'created_at', 'updated_at', 'deleted_at',
        'currency_id', 'exchange_rate', 'base_amount',
    ];

    protected $casts = [
        'GrandTotal' => 'double',
        'user_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',

        'currency_id' => 'integer',
        'exchange_rate' => 'double',
        'base_amount' => 'double',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function details()
    {
        return $this->hasMany('App\Models\QuotationDetail');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

}
