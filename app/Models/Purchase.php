<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted document is gone for every later edit, receipt or payment (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'Ref', 'provider_id', 'warehouse_id', 'GrandTotal',
        'discount', 'shipping', 'statut', 'notes', 'TaxNet', 'tax_rate', 'paid_amount',
        'payment_statut', 'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
        'short_closed_at', 'short_closed_by', 'short_close_reason',
        'created_at', 'updated_at', 'deleted_at',
        'currency_id', 'exchange_rate', 'base_amount',
    ];

    protected $casts = [
        'provider_id' => 'integer',
        'warehouse_id' => 'integer',
        'GrandTotal' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'TaxNet' => 'double',
        'tax_rate' => 'double',
        'paid_amount' => 'double',
        'currency_id' => 'integer',
        'exchange_rate' => 'double',
        'base_amount' => 'double',
    ];

    public function details()
    {
        return $this->hasMany('App\Models\PurchaseDetail');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function facture()
    {
        return $this->hasMany('App\Models\PaymentPurchase');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    /**
     * Post it to the ledger once this request has answered.
     *
     * A purchase reaches the books when the goods are received, which is
     * when the liability is real.
     *
     * On the model rather than in the controllers, so a document saved by a
     * path nobody remembered to wire still reaches the books. The posting
     * itself refuses to run twice, so saving the same record several times
     * in one request is harmless.
     */
    protected static function booted()
    {
        static::saved(function (self $model) {
            if ($model->statut === 'received') {
                \App\utils\LedgerPoster::postSoon('purchase', $model->id);
            }
        });
    }

}
