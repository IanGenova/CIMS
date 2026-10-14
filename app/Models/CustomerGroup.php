<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerGroup extends Model
{
    use SoftDeletes;

    protected $table = 'customer_groups';

    protected $fillable = ['name', 'default_discount_percent', 'is_active'];

    protected $casts = [
        'default_discount_percent' => 'double',
        'is_active' => 'boolean',
    ];

    public function clients()
    {
        return $this->hasMany('App\Models\Client', 'customer_group_id');
    }

    public function priceLists()
    {
        return $this->hasMany('App\Models\PriceList', 'customer_group_id');
    }
}
