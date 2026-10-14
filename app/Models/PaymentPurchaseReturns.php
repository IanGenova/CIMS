<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentPurchaseReturns extends Model
{
    use SoftDeletes;
    // Reaches the journal when saved, and is taken back out when changed (DATATEST K27).
    use \App\Models\Concerns\PostsToJournal;
    const LEDGER_KIND = 'payment_purchase_return';
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'purchase_return_id', 'date', 'montant','change', 'Ref', 'Reglement', 'user_id', 'notes',
    ];

    protected $casts = [
        'montant' => 'double',
        'change'  => 'double',
        'purchase_return_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function PurchaseReturn()
    {
        return $this->belongsTo('App\Models\PurchaseReturn');
    }

}
