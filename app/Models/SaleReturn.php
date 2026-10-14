<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit or refund (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;
    // Reaches the journal when saved, and is taken back out when changed (DATATEST K27).
    use \App\Models\Concerns\PostsToJournal;
    const LEDGER_KIND = 'sale_return';

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'GrandTotal',
        'user_id', 'discount', 'shipping',
        'warehouse_id', 'client_id', 'notes', 'TaxNet', 'tax_rate', 'statut',
        'paid_amount', 'payment_statut', 'created_at', 'updated_at', 'deleted_at',
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
        'paid_amount' => 'double',
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
        return $this->hasMany('App\Models\SaleReturnDetails');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentSaleReturns');
    }

}
