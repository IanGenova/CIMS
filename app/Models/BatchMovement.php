<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchMovement extends Model
{
    protected $table = 'batch_movements';

    /** Append-only: a movement is never updated, only offset by another. */
    public $timestamps = false;

    protected $fillable = [
        'product_batch_id', 'warehouse_id', 'qte', 'movement_type',
        'reference_type', 'reference_id', 'user_id', 'notes', 'created_at',
    ];

    protected $casts = [
        'product_batch_id' => 'integer',
        'warehouse_id' => 'integer',
        'qte' => 'double',
        'reference_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo('App\Models\ProductBatch', 'product_batch_id');
    }
}
