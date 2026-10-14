<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentSale extends Model
{
    use SoftDeletes;
    // Reaches the journal when saved, and is taken back out when changed (DATATEST K27).
    use \App\Models\Concerns\PostsToJournal;
    const LEDGER_KIND = 'payment_sale';
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'sale_id', 'date', 'montant', 'Ref','change', 'Reglement', 'user_id', 'notes',
    ];

    protected $casts = [
        'montant' => 'double',
        'change'  => 'double',
        'sale_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

}
