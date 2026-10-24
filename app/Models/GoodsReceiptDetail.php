<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptDetail extends Model
{
    protected $table = 'goods_receipt_details';

    protected $fillable = [
        'goods_receipt_id', 'purchase_detail_id', 'product_id', 'product_variant_id',
        'purchase_unit_id', 'ordered_qte', 'received_qte', 'rejected_qte', 'notes',
        'qc_status', 'qc_passed_qte', 'qc_failed_qte', 'qc_decided_by', 'qc_decided_at', 'qc_notes',
    ];

    protected $casts = [
        'goods_receipt_id' => 'integer',
        'purchase_detail_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'purchase_unit_id' => 'integer',
        'ordered_qte' => 'double',
        'received_qte' => 'double',
        'rejected_qte' => 'double',
        'qc_passed_qte' => 'double',
        'qc_failed_qte' => 'double',
        'qc_decided_at' => 'datetime',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo('App\Models\GoodsReceipt', 'goods_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function qcDecider()
    {
        return $this->belongsTo('App\Models\User', 'qc_decided_by');
    }
}
