<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceList extends Model
{
    use SoftDeletes;

    protected $table = 'price_lists';

    protected $fillable = [
        'name', 'customer_group_id', 'starts_on', 'ends_on', 'is_active',
    ];

    protected $casts = [
        'customer_group_id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany('App\Models\PriceListItem', 'price_list_id');
    }

    public function group()
    {
        return $this->belongsTo('App\Models\CustomerGroup', 'customer_group_id');
    }

    /** A list is usable only while active and inside its date window. */
    public function isLiveOn($date)
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->starts_on && $this->starts_on->format('Y-m-d') > $date) {
            return false;
        }
        if ($this->ends_on && $this->ends_on->format('Y-m-d') < $date) {
            return false;
        }

        return true;
    }
}
