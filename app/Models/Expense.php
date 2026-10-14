<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    // Staff tied to other branches neither list nor open it (ExpenseWarehouseTest).
    use \App\Models\Concerns\RestrictedToUserWarehouses;
    // A deleted expense is gone for every report and edit (DATATEST K9).
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'date', 'user_id', 'expense_category_id', 'warehouse_id', 'details',
        'amount', 'Ref', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expense_category_id' => 'integer',
        'warehouse_id' => 'integer',
        'amount' => 'double',
    ];

    public function warehouse()
    {
        return $this->belongsTo('App\Models\Warehouse');
    }

    public function expense_category()
    {
        return $this->belongsTo('App\Models\ExpenseCategory');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    /**
     * Post it to the ledger once this request has answered.
     *
     * An expense is an expense the moment it is recorded.
     *
     * On the model rather than in the controllers, so a document saved by a
     * path nobody remembered to wire still reaches the books. The posting
     * itself refuses to run twice, so saving the same record several times
     * in one request is harmless.
     */
    protected static function booted()
    {
        static::saved(function (self $model) {
            if (true) {
                \App\utils\LedgerPoster::postSoon('expense', $model->id);
            }
        });
    }

}
