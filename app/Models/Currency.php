<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code', 'name', 'symbol', 'exchange_rate', 'is_base', 'rate_updated_at',
    ];

    protected $casts = [
        'exchange_rate' => 'double',
        'is_base' => 'boolean',
    ];
}
