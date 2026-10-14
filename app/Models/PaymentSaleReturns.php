<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentSaleReturns extends Model
{
    use SoftDeletes;
    // Reaches the journal when saved, and is taken back out when changed (DATATEST K27).
    use \App\Models\Concerns\PostsToJournal;
    const LEDGER_KIND = 'payment_sale_return';
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'sale_return_id', 'date', 'montant','change', 'Ref', 'Reglement', 'user_id', 'notes',
    ];

    protected $casts = [
        'montant' => 'double',
        'change'  => 'double',
        'sale_return_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function SaleReturn()
    {
        return $this->belongsTo('App\Models\SaleReturn');
    }

}
