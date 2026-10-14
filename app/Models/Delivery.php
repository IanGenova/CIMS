<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use SoftDeletes;
    // Staff tied to other branches neither list nor open it (DeliveryWarehouseTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;

    protected $table = 'deliveries';

    protected $fillable = [
        'Ref', 'sale_id', 'client_id', 'warehouse_id', 'date', 'status',
        'courier', 'tracking_number', 'shipping_address',
        'dispatched_at', 'delivered_at', 'received_by',
        'failure_reason', 'notes', 'user_id',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'date' => 'date',
    ];

    /** The states a delivery may be in, and what may follow each. */
    public static function transitions()
    {
        return [
            'pending' => ['dispatched', 'failed'],
            'dispatched' => ['in_transit', 'delivered', 'failed'],
            'in_transit' => ['delivered', 'failed'],
            'delivered' => ['returned'],
            'failed' => ['pending', 'dispatched'],
            'returned' => [],
        ];
    }

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

    public function client()
    {
        return $this->belongsTo('App\Models\Client');
    }

    public function details()
    {
        return $this->hasMany('App\Models\DeliveryDetail');
    }
}
