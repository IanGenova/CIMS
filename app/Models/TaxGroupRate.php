<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxGroupRate extends Model
{
    protected $table = 'tax_group_rates';

    protected $fillable = ['tax_group_id', 'name', 'rate', 'is_compound', 'sort_order'];

    protected $casts = [
        'tax_group_id' => 'integer',
        'rate' => 'double',
        'is_compound' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function group()
    {
        return $this->belongsTo('App\Models\TaxGroup', 'tax_group_id');
    }
}
