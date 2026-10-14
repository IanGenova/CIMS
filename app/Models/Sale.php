<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit, void or payment (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'is_pos', 'client_id', 'GrandTotal', 'qte_retturn', 'TaxNet', 'tax_rate', 'notes',
        'total_retturn', 'warehouse_id', 'till_session_id', 'user_id', 'statut', 'discount', 'shipping',
        'paid_amount', 'payment_statut', 'created_at', 'updated_at', 'deleted_at',
        'currency_id', 'exchange_rate', 'base_amount',
    ];

    protected $casts = [
        'is_pos' => 'integer',
        'GrandTotal' => 'double',
        'qte_retturn' => 'double',
        'total_retturn' => 'double',
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
        return $this->hasMany('App\Models\SaleDetail');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentSale');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    /**
     * Post it to the ledger once this request has answered.
     *
     * A sale reaches the books when it is completed -- not when it is
     * a quotation or an order, and not when it has been voided.
     *
     * On the model rather than in the controllers, so a document saved by a
     * path nobody remembered to wire still reaches the books. The posting
     * itself refuses to run twice, so saving the same record several times
     * in one request is harmless.
     */
    protected static function booted()
    {
        static::saved(function (self $model) {
            if ($model->statut === 'completed') {
                \App\utils\LedgerPoster::postSoon('sale', $model->id);
            }
        });
    }

}
