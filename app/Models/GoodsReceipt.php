<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use SoftDeletes;
    // Staff tied to other branches neither list nor open it (WarehouseAccessTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;

    protected $table = 'goods_receipts';

    protected $fillable = [
        'Ref', 'date', 'purchase_id', 'warehouse_id', 'user_id',
        'status', 'delivery_note', 'notes', 'received_at',
    ];

    protected $casts = [
        'purchase_id' => 'integer',
        'warehouse_id' => 'integer',
        'user_id' => 'integer',
        'received_at' => 'datetime',
    ];

    public function details()
    {
        return $this->hasMany('App\Models\GoodsReceiptDetail', 'goods_receipt_id');
    }

    public function purchase()
    {
        return $this->belongsTo('App\Models\Purchase');
    }

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }
}
