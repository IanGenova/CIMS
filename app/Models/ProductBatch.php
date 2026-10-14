<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductBatch extends Model
{
    use SoftDeletes;

    protected $table = 'product_batches';

    protected $fillable = [
        'product_id', 'product_variant_id', 'batch_number',
        'manufacture_date', 'expiry_date', 'provider_id', 'cost', 'notes',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'provider_id' => 'integer',
        'cost' => 'double',
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function provider()
    {
        return $this->belongsTo('App\Models\Provider');
    }

    public function stock()
    {
        return $this->hasMany('App\Models\BatchStock', 'product_batch_id');
    }

    public function movements()
    {
        return $this->hasMany('App\Models\BatchMovement', 'product_batch_id');
    }

    /** Total held across every warehouse. */
    public function getTotalQteAttribute()
    {
        return (float) $this->stock()->sum('qte');
    }

    /** Null when the batch carries no expiry date. */
    public function getDaysToExpiryAttribute()
    {
        if (! $this->expiry_date) {
            return null;
        }

        return Carbon::today()->diffInDays($this->expiry_date, false);
    }

    public function getIsExpiredAttribute()
    {
        $days = $this->days_to_expiry;

        return $days !== null && $days < 0;
    }

    /**
     * expired | expiring | ok | none — the single label the UI and reports use
     * so the thresholds live in one place.
     */
    public function getExpiryStatusAttribute()
    {
        $days = $this->days_to_expiry;

        if ($days === null) {
            return 'none';
        }

        if ($days < 0) {
            return 'expired';
        }

        $window = $this->product && $this->product->expiry_alert_days !== null
            ? (int) $this->product->expiry_alert_days
            : 30;

        return $days <= $window ? 'expiring' : 'ok';
    }
}
