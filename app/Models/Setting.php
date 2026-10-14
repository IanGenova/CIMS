<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{

    protected $fillable = [
        'currency_id', 'email', 'CompanyName', 'CompanyPhone', 'CompanyAdress',
         'logo','footer','developed_by','client_id','warehouse_id','default_language',
         'purchase_approval_threshold',
    ];

    protected $casts = [
        'currency_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        // Xendit keys, encrypted with the app key (App\utils\XenditCheckout).
        'xendit_secret_key' => 'encrypted',
        'xendit_webhook_token' => 'encrypted',
    ];

    /** Never in a JSON response, whatever asks for the settings row. */
    protected $hidden = ['xendit_secret_key', 'xendit_webhook_token'];

    public function Currency()
    {
        return $this->belongsTo('App\Models\Currency');
    }

}
