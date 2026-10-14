<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $table = 'accounts';

    protected $fillable = [
        'code', 'name', 'type', 'parent_id', 'normal_balance',
        'slug', 'is_active', 'is_system',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public static function types()
    {
        return ['asset', 'liability', 'equity', 'income', 'expense'];
    }

    public function lines()
    {
        return $this->hasMany('App\Models\JournalLine', 'account_id');
    }
}
