<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'code', 'adresse', 'email', 'phone', 'country', 'city',
        'customer_group_id', 'is_tax_exempt', 'tax_number',
        'credit_limit', 'opening_balance',
    ];

    protected $casts = [
        'code' => 'integer',
        'customer_group_id' => 'integer',
        'is_tax_exempt' => 'boolean',
        'credit_limit' => 'double',
        'opening_balance' => 'double',
    ];

    public function group()
    {
        return $this->belongsTo('App\Models\CustomerGroup', 'customer_group_id');
    }
}
