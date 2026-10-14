<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxGroup extends Model
{
    use SoftDeletes;

    protected $table = 'tax_groups';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function rates()
    {
        return $this->hasMany('App\Models\TaxGroupRate', 'tax_group_id')
            ->orderBy('sort_order')->orderBy('id');
    }

    public function products()
    {
        return $this->hasMany('App\Models\Product', 'tax_group_id');
    }
}
